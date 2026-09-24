<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AttachmentInspector;
use App\Support\WorkflowSubject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adjuntos seguros de tickets y actividades: disco privado (`storage/app/private`), nombre aleatorio elegido
 * por el servidor, extensión de la lista blanca validada contra el MIME real y nombre original saneado. Se
 * sirven únicamente por AttachmentController (con Policy); nunca desde `public/`. Cada tipo de registro
 * tiene su propio directorio (los ids de tickets y actividades pueden coincidir).
 */
final class AttachmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttachmentInspector $inspector,
    ) {}

    public function store(User $actor, Ticket|Activity $subject, UploadedFile $file): Attachment
    {
        // Se revalida aquí aunque el Form Request ya lo hizo: el servicio no confía en su llamador.
        $extension = $this->inspector->acceptedExtension($file);

        if ($extension === null) {
            throw BusinessRuleException::because('tickets.validation.attachment_type');
        }

        if ($file->getSize() > (int) config('tickets.attachments.max_kilobytes') * 1024) {
            throw BusinessRuleException::because('tickets.validation.attachment_size', ['max' => (int) config('tickets.attachments.max_kilobytes') / 1024]);
        }

        $disk = Storage::disk((string) config('tickets.attachments.disk'));
        $directory = WorkflowSubject::attachmentDirectory($subject);
        $storedName = Str::random(40).'.'.$extension;
        $path = null;

        try {
            return DB::transaction(function () use ($actor, $subject, $file, $disk, $directory, $storedName, &$path): Attachment {
                // Bloquear el registro serializa las subidas concurrentes: el tope por registro no se rebasa.
                $subject::query()->lockForUpdate()->findOrFail($subject->getKey());

                if ($subject->attachments()->count() >= (int) config('tickets.attachments.max_per_ticket')) {
                    throw BusinessRuleException::because(WorkflowSubject::error($subject, 'too_many_attachments'), ['max' => (int) config('tickets.attachments.max_per_ticket')]);
                }

                $path = $disk->putFileAs($directory, $file, $storedName);

                if ($path === false) {
                    throw BusinessRuleException::because(WorkflowSubject::error($subject, 'upload_failed'));
                }

                $attachment = new Attachment([
                    'user_id' => $actor->getKey(),
                    'original_name' => $this->inspector->sanitizeName($file->getClientOriginalName()),
                    'path' => $path,
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ]);
                $subject->attachments()->save($attachment);

                $this->audit->record(WorkflowSubject::group($subject), 'attachment_added', $subject, $actor, [], [], [
                    'folio' => $subject->folio,
                    'attachment_id' => $attachment->getKey(),
                    'original_name' => $attachment->original_name,
                ]);

                return $attachment;
            });
        } catch (Throwable $exception) {
            // Si algo falló después de escribir el archivo, no se deja huérfano en disco.
            if (is_string($path)) {
                $disk->delete($path);
            }

            throw $exception;
        }
    }

    public function delete(User $actor, Attachment $attachment): void
    {
        $subject = $attachment->attachable;
        $path = $attachment->path;

        DB::transaction(function () use ($actor, $attachment, $subject): void {
            $attachment->delete();

            $this->audit->record($subject === null ? 'tickets' : WorkflowSubject::group($subject), 'attachment_removed', $subject, $actor, [], [], [
                'folio' => $subject?->folio,
                'attachment_id' => $attachment->getKey(),
                'original_name' => $attachment->original_name,
            ]);
        });

        Storage::disk((string) config('tickets.attachments.disk'))->delete($path);
    }
}
