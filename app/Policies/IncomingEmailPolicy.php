<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\IncomingEmailStatus;
use App\Enums\PermissionName;
use App\Models\IncomingEmail;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Bandeja de correos entrantes: administrador (todos) y jefe de zona/coordinador de la coordinacion del correo
 * (permiso `emails.view`). Un correo de otra coordinacion responde 404. Descartar o convertir exige
 * `emails.manage` y que el correo siga `PendingReview` (una vez revisado, ninguna de las dos acciones vuelve a
 * estar disponible).
 */
class IncomingEmailPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::EmailsView);
    }

    public function view(User $user, IncomingEmail $incomingEmail): Response|bool
    {
        return $this->scoped($user, $incomingEmail, PermissionName::EmailsView);
    }

    public function discard(User $user, IncomingEmail $incomingEmail): Response|bool
    {
        return $this->scoped($user, $incomingEmail, PermissionName::EmailsManage, requirePending: true);
    }

    public function convert(User $user, IncomingEmail $incomingEmail): Response|bool
    {
        return $this->scoped($user, $incomingEmail, PermissionName::EmailsManage, requirePending: true);
    }

    private function scoped(User $user, IncomingEmail $incomingEmail, PermissionName $permission, bool $requirePending = false): Response|bool
    {
        if (! $this->hasPermission($user, $permission)) {
            return false;
        }

        if (! IncomingEmail::query()->whereKey($incomingEmail->getKey())->visibleTo($user)->exists()) {
            return Response::denyAsNotFound();
        }

        return ! $requirePending || $incomingEmail->status === IncomingEmailStatus::PendingReview;
    }
}
