<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AttachmentInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adjuntos seguros: disco privado (`storage/app/private`), nombre aleatorio elegido por el servidor,
 * extensión de la lista blanca validada contra el MIME real y nombre original saneado. Se sirven
 * únicamente por AttachmentController (con Policy); nunca desde `public/`.
 */
final class AttachmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttachmentInspector $inspector,
    ) {}

    public function store(User $actor, Ticket $ticket, UploadedFile $file): Attachment
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
        $directory = trim((string) config('tickets.attachments.directory'), '/').'/'.(int) $ticket->getKey();
        $storedName = Str::random(40).'.'.$extension;
        $path = null;

        try {
            return DB::transaction(function () use ($actor, $ticket, $file, $disk, $directory, $storedName, &$path): Attachment {
                // Bloquear el ticket serializa las subidas concurrentes: el tope por ticket no se rebasa.
                Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

                if ($ticket->attachments()->count() >= (int) config('tickets.attachments.max_per_ticket')) {
                    throw BusinessRuleException::because('tickets.errors.too_many_attachments', ['max' => (int) config('tickets.attachments.max_per_ticket')]);
                }

                $path = $disk->putFileAs($directory, $file, $storedName);

                if ($path === false) {
                    throw BusinessRuleException::because('tickets.errors.upload_failed');
                }

                $attachment = new Attachment([
                    'user_id' => $actor->getKey(),
                    'original_name' => $this->inspector->sanitizeName($file->getClientOriginalName()),
                    'path' => $path,
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ]);
                $ticket->attachments()->save($attachment);

                $this->audit->record('tickets', 'attachment_added', $ticket, $actor, [], [], [
                    'folio' => $ticket->folio,
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
        $ticket = $attachment->attachable;
        $path = $attachment->path;

        DB::transaction(function () use ($actor, $attachment, $ticket): void {
            $attachment->delete();

            $this->audit->record('tickets', 'attachment_removed', $ticket, $actor, [], [], [
                'folio' => $ticket?->folio,
                'attachment_id' => $attachment->getKey(),
                'original_name' => $attachment->original_name,
            ]);
        });

        Storage::disk((string) config('tickets.attachments.disk'))->delete($path);
    }
}
