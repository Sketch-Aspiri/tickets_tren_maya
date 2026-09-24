<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unico punto que corta el acceso ya concedido a un usuario: rota el `remember_token`
 * (invalida las cookies "recordarme") y borra sus filas de `sessions`.
 *
 * Se usa al cambiar/restablecer la contrasena, inactivar, rechazar y reactivar, y al restablecer
 * el 2FA por consola. Borrar las filas tambien elimina la marca `two_factor.verified_user_id`,
 * que vive en el payload de la sesion, para que una reactivacion no "resucite" verificaciones viejas.
 *
 * Borrar filas solo aplica con el driver `database` (el de produccion); con otro driver la
 * proteccion es AuthenticateSession (contrasena) y EnsureAccountIsActive (cuenta inactiva).
 */
final class SessionInvalidator
{
    private const REMEMBER_TOKEN_LENGTH = 60;

    /**
     * @param  string|null  $exceptSessionId  sesion actual que debe sobrevivir (cambio de contrasena propio)
     */
    public function revokeAll(User $user, ?string $exceptSessionId = null): void
    {
        $user->forceFill(['remember_token' => Str::random(self::REMEMBER_TOKEN_LENGTH)])->saveQuietly();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
