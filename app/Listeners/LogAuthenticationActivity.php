<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;

/**
 * Se registra explicitamente como subscriber (AppServiceProvider); los metodos no empiezan con "handle" para
 * que el descubrimiento automatico de eventos no los registre dos veces.
 *
 * Bitacora de autenticacion: login, logout, intentos fallidos y restablecimiento de contrasena.
 * Nunca se registran contrasenas ni tokens; del intento fallido solo el correo y la IP.
 */
final class LogAuthenticationActivity
{
    private const LOG = 'auth';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            PasswordReset::class => 'onPasswordReset',
        ];
    }

    public function onLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(self::LOG, 'login', $event->user, $event->user, extra: $this->context());
        }
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(self::LOG, 'logout', $event->user, $event->user, extra: $this->context());
        }
    }

    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        $this->audit->record(
            self::LOG,
            'login_failed',
            $event->user instanceof User ? $event->user : null,
            null,
            extra: $this->context() + ['email' => is_string($email) ? Str::limit($email, 255, '') : null],
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(self::LOG, 'password_reset', $event->user, $event->user, extra: $this->context());
        }
    }

    /**
     * @return array{ip: ?string}
     */
    private function context(): array
    {
        return ['ip' => request()->ip()];
    }
}
