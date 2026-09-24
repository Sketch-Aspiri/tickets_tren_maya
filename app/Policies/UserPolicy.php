<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Gestion de usuarios: solo el jefe de zona (permisos `users.manage` / `users.approve`).
 * Las habilidades de 2FA actuan siempre sobre la cuenta propia.
 */
class UserPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::UsersManage);
    }

    public function view(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersManage);
    }

    public function update(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersManage);
    }

    public function approve(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersApprove);
    }

    public function reject(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersApprove);
    }

    public function activate(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersManage);
    }

    public function deactivate(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersManage);
    }

    /**
     * Cambiar nombre y contrasena propios.
     */
    public function updateProfile(User $user, User $target): bool
    {
        return $user->is($target) && $user->canAccessApplication();
    }

    /**
     * Configurar / confirmar / regenerar codigos de 2FA: solo sobre la propia cuenta activa.
     */
    public function manageTwoFactor(User $user, User $target): bool
    {
        return $user->is($target) && $user->canAccessApplication();
    }

    /**
     * Desactivar 2FA: solo la propia cuenta y solo si su rol no lo exige (empleados).
     */
    public function disableTwoFactor(User $user, User $target): bool
    {
        return $this->manageTwoFactor($user, $target) && ! $user->requiresTwoFactor();
    }
}
