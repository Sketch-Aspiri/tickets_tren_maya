<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MailboxResolver;
use App\Exceptions\BusinessRuleException;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use App\Models\User;
use Throwable;

/**
 * Alta, edicion y prueba del buzon de una coordinacion. La contrasena nunca se devuelve ni se registra: un campo
 * vacio al editar conserva la actual, y la bitacora solo guarda los datos no secretos.
 */
final class MailboxService
{
    public function __construct(
        private readonly MailboxResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{host: string, port: int, encryption: string, username: string, password?: ?string, folder: string, active: bool}  $data
     */
    public function save(User $actor, Coordination $coordination, array $data): CoordinationMailbox
    {
        $mailbox = $coordination->mailbox ?? new CoordinationMailbox(['coordination_id' => $coordination->getKey()]);
        $isNew = ! $mailbox->exists;
        $before = $isNew ? [] : $this->visible($mailbox);

        $attributes = [
            'host' => strtolower($data['host']),
            'port' => $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'],
            'folder' => $data['folder'],
            'active' => $data['active'],
        ];

        if (($data['password'] ?? '') !== '') {
            $attributes['password'] = $data['password'];
        }

        $mailbox->fill($attributes)->save();

        $this->audit->record('mailboxes', $isNew ? 'created' : 'updated', $coordination, $actor, $before, $this->visible($mailbox), [
            'password_changed' => array_key_exists('password', $attributes),
        ]);

        return $mailbox;
    }

    public function delete(User $actor, Coordination $coordination): void
    {
        $mailbox = $coordination->mailbox;

        if ($mailbox === null) {
            return;
        }

        $before = $this->visible($mailbox);
        $mailbox->delete();

        $this->audit->record('mailboxes', 'deleted', $coordination, $actor, $before);
    }

    /**
     * Prueba la conexion. Devuelve un motivo corto y SIN credenciales si falla; null si conecto.
     */
    public function test(User $actor, Coordination $coordination): ?string
    {
        $mailbox = $coordination->mailbox;

        if ($mailbox === null) {
            throw BusinessRuleException::because('mailboxes.errors.not_configured');
        }

        try {
            $this->resolver->test($mailbox);
        } catch (Throwable $exception) {
            $reason = mb_substr(class_basename($exception).': '.str_replace(array_filter([$mailbox->password, $mailbox->username]), '***', $exception->getMessage()), 0, 300);
            $this->audit->record('mailboxes', 'test_failed', $coordination, $actor, extra: ['reason' => $reason]);

            return $reason;
        }

        $this->audit->record('mailboxes', 'test_ok', $coordination, $actor);

        return null;
    }

    /**
     * Datos no secretos del buzon (para bitacora y comparaciones).
     *
     * @return array<string, mixed>
     */
    private function visible(CoordinationMailbox $mailbox): array
    {
        return $mailbox->only(['host', 'port', 'encryption', 'username', 'folder', 'active']);
    }
}
