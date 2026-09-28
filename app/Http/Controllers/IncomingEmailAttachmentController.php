<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IncomingEmailAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de un adjunto de correo entrante AÚN NO revisado. Mismas cabeceras de seguridad que
 * `AttachmentController::download` (tickets/actividades); la autorización hereda del correo padre
 * vía `IncomingEmailAttachmentPolicy`.
 */
class IncomingEmailAttachmentController extends Controller
{
    public function download(IncomingEmailAttachment $incomingEmailAttachment): StreamedResponse
    {
        $this->authorize('view', $incomingEmailAttachment);

        $disk = Storage::disk((string) config('tickets.attachments.disk'));

        abort_unless($disk->exists($incomingEmailAttachment->path), 404);

        return $disk->download($incomingEmailAttachment->path, $incomingEmailAttachment->original_name, [
            'Content-Type' => $incomingEmailAttachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
