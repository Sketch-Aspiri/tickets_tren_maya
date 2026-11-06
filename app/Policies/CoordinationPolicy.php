<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Coordination;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Estructura organizacional: solo quien tiene `org.manage` (administrador).
 */
class CoordinationPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function view(User $user, Coordination $coordination): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function update(User $user, Coordination $coordination): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function delete(User $user, Coordination $coordination): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    /**
     * Ver la pantalla "Mi bandeja": administrador, o jefe/coordinador que gestiona correos entrantes.
     */
    public function viewInboxSettings(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage) || $this->hasPermission($user, PermissionName::EmailsManage);
    }

    /**
     * Configurar y sincronizar la bandeja de UNA coordinacion (buzon IMAP y hoja de Excel): el administrador en
     * cualquiera; el jefe y el coordinador solo en las suyas (otra coordinacion responde 404, sin revelar nada).
     */
    public function manageInbox(User $user, Coordination $coordination): Response|bool
    {
        if ($this->hasPermission($user, PermissionName::OrgManage)) {
            return true;
        }

        if (! $this->hasPermission($user, PermissionName::EmailsManage)) {
            return false;
        }

        return in_array((int) $coordination->getKey(), $user->coordinationIds(), true) ? true : Response::denyAsNotFound();
    }
}
