<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MailboxAttachment;
use App\Contracts\MailboxClient;
use App\Contracts\MailboxMessage;
use App\Enums\IncomingEmailStatus;
use App\Models\IncomingEmail;
use App\Support\AttachmentInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingesta de correo entrante (bandeja "Correos entrantes"): descarga los mensajes no leídos del buzón
 * configurado y los deja pendientes de revisión humana (`IncomingEmail`, status `PendingReview`). Aísla
 * los fallos por mensaje (mismo patrón que RecurrenceService::generateAll(): try/catch + report(), sin
 * abortar el lote) y nunca borra ni edita nada existente.
 *
 * El cuerpo en texto plano y la detección de MIME de los adjuntos ya llegan resueltos por el
 * MailboxClient inyectado; este servicio nunca interpreta HTML ni cabeceras crudas más allá de
 * Auto-Submitted/Precedence (autorespuestas). El contenido del correo es SIEMPRE dato no confiable: se
 * guarda tal cual, nunca se interpreta como instrucción.
 */
final class EmailIngestionService
{
    private const AUTORESPONDER_PRECEDENCE = ['bulk', 'junk', 'list'];

    public function __construct(
        private readonly MailboxClient $mailbox,
        private readonly AttachmentInspector $inspector,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{fetched:int, created:int, duplicate:int, skipped_autoresponder:int, skipped_invalid:int, failed:int, capped:bool}
     */
    public function ingest(): array
    {
        $messages = $this->mailbox->fetchUnseenMessages();
        $maxPerRun = (int) config('mail_ingestion.max_per_run');

        $result = [
            'fetched' => count($messages),
            'created' => 0,
            'duplicate' => 0,
            'skipped_autoresponder' => 0,
            'skipped_invalid' => 0,
            'failed' => 0,
            // Tope global por corrida (defensa en profundidad contra un remitente que rota direcciones):
            // los mensajes por encima de este numero se dejan sin procesar (ni leidos ni contados como
            // failed) para la siguiente corrida.
            'capped' => $maxPerRun > 0 && count($messages) > $maxPerRun,
        ];

        foreach ($messages as $index => $message) {
            if ($maxPerRun > 0 && $index >= $maxPerRun) {
                break;
            }

            try {
                $this->processMessage($message, $result);
            } catch (Throwable $exception) {
                $result['failed']++;
                report($exception);
            }
        }

        return $result;
    }

    /**
     * @param  array{fetched:int, created:int, duplicate:int, skipped_autoresponder:int, skipped_invalid:int, failed:int, capped:bool}  $result
     */
    private function processMessage(MailboxMessage $message, array &$result): void
    {
        $tempPaths = [];

        try {
            $messageId = $this->messageIdFor($message);
            // Sanitizado una sola vez: se usa igual para comprobar el limite por remitente que para
            // persistir, asi ambos comparan el mismo valor (el que de verdad queda guardado).
            $fromEmail = $this->sanitizeSenderText($message->fromEmail);

            if (IncomingEmail::query()->where('message_id', $messageId)->exists()) {
                $result['duplicate']++;
                $this->mailbox->markAsRead($message);

                return;
            }

            if ($this->isAutoresponder($message)) {
                $result['skipped_autoresponder']++;
                $this->mailbox->markAsRead($message);

                return;
            }

            if ($message->sizeBytes > (int) config('mail_ingestion.max_email_kb') * 1024) {
                $result['skipped_invalid']++;
                $this->mailbox->markAsRead($message);

                return;
            }

            if (count($message->attachments) > (int) config('mail_ingestion.max_attachments_per_email')) {
                $result['skipped_invalid']++;
                $this->mailbox->markAsRead($message);

                return;
            }

            // Barato antes que caro: el limite por remitente se comprueba ANTES de validar adjuntos
            // (E/S en disco + apertura de ZIP para docx/xlsx). Asi, un remitente sobre el limite no
            // repite ese trabajo costoso en cada corrida sobre el mismo correo mientras siga sobre el
            // limite.
            if ($this->exceedsSenderRateLimit($fromEmail)) {
                // A proposito NO se marca como leido: se reintenta en la siguiente corrida, cuando
                // probablemente el remitente ya no exceda el limite.
                $result['skipped_invalid']++;

                return;
            }

            $validAttachments = $this->validateAttachments($message->attachments, $tempPaths);

            if ($validAttachments === null) {
                $result['skipped_invalid']++;
                $this->mailbox->markAsRead($message);

                return;
            }

            $this->persist($message, $messageId, $fromEmail, $validAttachments);
            $this->mailbox->markAsRead($message);
            $result['created']++;
        } finally {
            $this->cleanupTempFiles($tempPaths);
        }
    }

    /**
     * Header `Message-ID` real, o un hash determinista de from+subject+date si el correo no trae uno.
     * Nunca es nulo: una sola columna unique basta para el dedup.
     */
    private function messageIdFor(MailboxMessage $message): string
    {
        $header = trim((string) $message->messageId);

        if ($header !== '') {
            return $header;
        }

        return 'sha256:'.hash('sha256', $message->fromEmail.'|'.$message->subject.'|'.$message->date->toIso8601String());
    }

    private function isAutoresponder(MailboxMessage $message): bool
    {
        $autoSubmitted = $message->autoSubmitted !== null ? strtolower(trim($message->autoSubmitted)) : null;

        if ($autoSubmitted !== null && $autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        $precedence = $message->precedence !== null ? strtolower(trim($message->precedence)) : null;

        return $precedence !== null && in_array($precedence, self::AUTORESPONDER_PRECEDENCE, true);
    }

    private function exceedsSenderRateLimit(string $fromEmail): bool
    {
        $count = IncomingEmail::query()
            ->where('from_email', $fromEmail)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        return $count >= (int) config('mail_ingestion.max_per_sender_per_hour');
    }

    /**
     * Quita caracteres de control y recorta a `$maxLength`. Deliberadamente distinto de
     * AttachmentInspector::sanitizeName() (esa es para nombres de archivo: otra longitud y otras reglas).
     * Defensa en profundidad: nada renderiza estos valores sin escapar hoy, pero el contenido de un
     * correo (asunto, remitente) es SIEMPRE dato no confiable.
     */
    private function sanitizeHeaderText(string $value, int $maxLength): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return mb_substr(trim($value), 0, $maxLength);
    }

    private function sanitizeSubject(string $subject): string
    {
        return $this->sanitizeHeaderText($subject, 500);
    }

    /**
     * Longitud igual a la columna `incoming_emails.from_email`/`from_name` (255, ver migracion).
     */
    private function sanitizeSenderText(string $value): string
    {
        return $this->sanitizeHeaderText($value, 255);
    }

    /**
     * Valida TODOS los adjuntos con el mismo AttachmentInspector que tickets/actividades. Si alguno
     * falla, el correo entero se rechaza (se devuelve null) — sin ingesta parcial. Los temporales creados
     * se acumulan en $tempPaths para limpiarse siempre, sea cual sea el resultado.
     *
     * @param  list<MailboxAttachment>  $attachments
     * @param  list<string>  $tempPaths
     * @return null|list<array{original_name:string, uploaded:UploadedFile, mime:string, size:int}>
     */
    private function validateAttachments(array $attachments, array &$tempPaths): ?array
    {
        $validated = [];

        foreach ($attachments as $attachment) {
            $tempPath = tempnam(sys_get_temp_dir(), 'imap_');

            if ($tempPath === false) {
                return null;
            }

            $tempPaths[] = $tempPath;
            file_put_contents($tempPath, $attachment->content);

            $uploaded = new UploadedFile($tempPath, $attachment->originalName, null, null, true);

            if ($this->inspector->acceptedExtension($uploaded) === null) {
                return null;
            }

            $validated[] = [
                'original_name' => $this->inspector->sanitizeName($attachment->originalName),
                'uploaded' => $uploaded,
                'mime' => (string) $uploaded->getMimeType(),
                'size' => (int) $uploaded->getSize(),
            ];
        }

        return $validated;
    }

    /**
     * @param  list<array{original_name:string, uploaded:UploadedFile, mime:string, size:int}>  $attachments
     */
    private function persist(MailboxMessage $message, string $messageId, string $fromEmail, array $attachments): void
    {
        DB::transaction(function () use ($message, $messageId, $fromEmail, $attachments): void {
            $email = new IncomingEmail([
                'message_id' => $messageId,
                'from_email' => $fromEmail,
                'from_name' => $message->fromName !== null ? $this->sanitizeSenderText($message->fromName) : null,
                'subject' => $this->sanitizeSubject($message->subject),
                'body' => $message->body,
                'received_at' => $message->date,
            ]);
            $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

            $disk = Storage::disk((string) config('tickets.attachments.disk', 'local'));

            foreach ($attachments as $attachment) {
                $extension = strtolower((string) $attachment['uploaded']->getClientOriginalExtension());
                $storedName = Str::random(40).'.'.$extension;
                $path = $disk->putFileAs("incoming-emails/{$email->id}", $attachment['uploaded'], $storedName);

                $email->attachments()->create([
                    'original_name' => $attachment['original_name'],
                    'path' => $path,
                    'mime' => $attachment['mime'],
                    'size' => $attachment['size'],
                ]);
            }

            $this->audit->record('emails', 'received', $email, null, [], [], [
                'from' => $fromEmail,
                'subject' => $email->subject,
            ]);
        });
    }

    /**
     * @param  list<string>  $tempPaths
     */
    private function cleanupTempFiles(array $tempPaths): void
    {
        foreach ($tempPaths as $tempPath) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
