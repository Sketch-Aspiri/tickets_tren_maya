<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\IncomingEmailStatus;
use App\Enums\PermissionName;
use App\Models\IncomingEmail;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Bandeja de correos entrantes: jefe de zona, administrador y coordinador (permiso `emails.view`), sin
 * recorte por equipo (un correo externo no tiene equipo hasta que alguien lo convierte). Descartar o
 * convertir exige `emails.manage` y que el correo siga `PendingReview` (una vez revisado, ninguna de las
 * dos acciones vuelve a estar disponible).
 */
class IncomingEmailPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::EmailsView);
    }

    public function view(User $user, IncomingEmail $incomingEmail): bool
    {
        return $this->hasPermission($user, PermissionName::EmailsView);
    }

    public function discard(User $user, IncomingEmail $incomingEmail): bool
    {
        return $this->hasPermission($user, PermissionName::EmailsManage)
            && $incomingEmail->status === IncomingEmailStatus::PendingReview;
    }

    public function convert(User $user, IncomingEmail $incomingEmail): bool
    {
        return $this->hasPermission($user, PermissionName::EmailsManage)
            && $incomingEmail->status === IncomingEmailStatus::PendingReview;
    }
}
