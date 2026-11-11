<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Models\User;
use App\Notifications\EcosystemNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos del Dashboard a personas de Tickets (decision 91). Solo llegan a cuentas activas, con rol y
 * VINCULADAS (decision 88): el registro de Tickets no verifica correos, asi que el correo solo no prueba que
 * la cuenta sea de esa persona. Quien no cumple se omite en silencio (no se revela cuales correos existen).
 */
final class EcosystemNoticeService
{
    public function __construct(
        private readonly SingleSignOnService $sso,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $emails
     * @return int Cuentas a las que se envio el aviso.
     */
    public function send(string $source, array $emails, string $title, string $body, string $url, ?string $ref): int
    {
        // Idempotencia: el Dashboard reintenta si no recibe la respuesta; el mismo `ref` no avisa dos veces.
        $key = $ref === null ? null : 'ecosistema:aviso:'.hash('sha256', $source.'|'.$ref);

        if ($key !== null && ! Cache::add($key, true, now()->addDay())) {
            return 0;
        }

        try {
            return $this->deliver($source, $emails, $title, $body, $url);
        } catch (\Throwable $e) {
            // Si el envio falla, el reintento del Dashboard con el mismo `ref` debe poder enviarlo.
            if ($key !== null) {
                Cache::forget($key);
            }

            throw $e;
        }
    }

    /**
     * @param  list<string>  $emails
     */
    private function deliver(string $source, array $emails, string $title, string $body, string $url): int
    {
        $users = collect($emails)
            ->map(fn (string $email): string => mb_strtolower($email))
            ->unique()
            ->map(fn (string $email): ?User => $this->sso->accountFor($email))
            ->filter(fn (?User $user): bool => $user !== null && $this->sso->isLinked($user))
            ->unique(fn (User $user): int|string => $user->getKey())
            ->values();

        if ($users->isEmpty()) {
            return 0;
        }

        Notification::send($users, new EcosystemNotice($title, $body, $url, $source));

        $this->audit->record('ecosystem', 'notice_sent', extra: ['source' => $source, 'recipients' => $users->count()]);

        return $users->count();
    }
}
