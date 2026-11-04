<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MailboxResolver;
use App\Models\CoordinationMailbox;
use App\Support\AttachmentInspector;
use Throwable;

/**
 * Recorre los buzones activos y procesa cada uno aislado: el fallo de un buzon se guarda en `last_error` (sin
 * credenciales) y en la bitacora, y NO detiene a los demas (mismo patron que RecurrenceService::generateAll()).
 */
final class MailboxPoller
{
    public function __construct(
        private readonly MailboxResolver $resolver,
        private readonly AttachmentInspector $inspector,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{coordination_id: int, ok: bool, result: ?array<string, mixed>}>
     */
    public function pollAll(): array
    {
        $summary = [];

        foreach (CoordinationMailbox::query()->active()
            ->whereHas('coordination', fn ($q) => $q->active()->whereHas('station', fn ($s) => $s->active()))
            ->orderBy('id')->get() as $mailbox) {
            $summary[] = $this->poll($mailbox);
        }

        return $summary;
    }

    /**
     * @return array{coordination_id: int, ok: bool, result: ?array<string, mixed>}
     */
    public function poll(CoordinationMailbox $mailbox): array
    {
        try {
            $ingestion = new EmailIngestionService($this->resolver->clientFor($mailbox), $this->inspector, $this->audit);
            $result = $ingestion->ingest($mailbox->coordination_id);

            $mailbox->forceFill(['last_polled_at' => now(), 'last_error' => null])->save();

            return ['coordination_id' => $mailbox->coordination_id, 'ok' => $result['failed'] === 0, 'result' => $result];
        } catch (Throwable $exception) {
            $reason = $this->safeReason($exception, [$mailbox->password, $mailbox->username]);
            $mailbox->forceFill(['last_polled_at' => now(), 'last_error' => $reason])->save();
            $this->audit->record('mailboxes', 'poll_failed', $mailbox->coordination, null, extra: ['reason' => $reason]);

            return ['coordination_id' => $mailbox->coordination_id, 'ok' => false, 'result' => null];
        }
    }

    /**
     * @param  list<string>  $secrets
     */
    private function scrub(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }

    /**
     * Motivo corto y sin datos sensibles: clase de la excepcion y un mensaje recortado sin la contrasena.
     */
    private function safeReason(Throwable $exception, array $secrets): string
    {
        return mb_substr(class_basename($exception).': '.$this->scrub($exception->getMessage(), $secrets), 0, 300);
    }
}
