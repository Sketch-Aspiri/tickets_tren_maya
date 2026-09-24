<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attachments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachments\DeleteAttachmentRequest;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Único punto de acceso a los archivos adjuntos: los archivos viven en el disco privado y nunca
 * tienen URL pública; toda descarga pasa por la Policy (`view`, que hereda el alcance del ticket o de la actividad dueña).
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        $disk = Storage::disk((string) config('tickets.attachments.disk'));

        abort_unless($disk->exists($attachment->path), 404);

        // Siempre como descarga (`attachment`), nunca `inline`, y sin que el navegador adivine el tipo.
        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(DeleteAttachmentRequest $request, Attachment $attachment): RedirectResponse
    {
        $this->authorize('delete', $attachment);

        $parent = $attachment->attachable;

        $this->attachments->delete($request->user(), $attachment);

        return match (true) {
            $parent instanceof Ticket => redirect()->route('tickets.show', $parent)->withFragment('adjuntos')->with('status', 'attachment-deleted'),
            $parent instanceof Activity => redirect()->route('activities.show', $parent)->withFragment('adjuntos')->with('status', 'attachment-deleted'),
            default => redirect()->route('tickets.index')->with('status', 'attachment-deleted'),
        };
    }
}
