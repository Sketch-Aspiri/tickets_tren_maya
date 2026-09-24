<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cambios que el usuario hace sobre su propia cuenta.
 */
final class UserProfileService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionInvalidator $sessions,
    ) {}

    /**
     * El cambio de nombre lo registra el trait LogsActivity del modelo.
     */
    public function updateName(User $user, string $name): User
    {
        $user->update(['name' => $name]);

        return $user;
    }

    /**
     * La contrasena nunca entra a la bitacora: solo el hecho del cambio. Cierra las demas sesiones
     * del usuario (y rota su remember token); la sesion actual sobrevive.
     */
    public function changePassword(User $user, string $newPassword, ?string $currentSessionId = null): User
    {
        DB::transaction(function () use ($user, $newPassword, $currentSessionId): void {
            $user->update(['password' => $newPassword]);
            $this->sessions->revokeAll($user, $currentSessionId);
            $this->audit->record('auth', 'password_changed', $user, $user, extra: ['ip' => request()->ip()]);
        });

        return $user;
    }

    /**
     * Restablecimiento por enlace de correo: la contrasena nueva reemplaza a la anterior y
     * ninguna sesion previa conserva acceso.
     */
    public function resetPassword(User $user, string $newPassword): User
    {
        DB::transaction(function () use ($user, $newPassword): void {
            $user->forceFill(['password' => $newPassword])->save();
            $this->sessions->revokeAll($user);
        });

        return $user;
    }
}
