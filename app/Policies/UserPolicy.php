<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Gestion de usuarios: administrador y jefe de zona (permisos `users.manage` / `users.approve`). Las cuentas de
 * administrador solo las gestiona quien tiene ademas `admins.manage` (el administrador): un jefe de zona puede
 * verlas, pero no aprobarlas, editarlas, activarlas ni inactivarlas.
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
        return $this->hasPermission($user, PermissionName::UsersManage) && $this->mayTouch($user, $target);
    }

    public function approve(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersApprove) && $this->mayTouch($user, $target);
    }

    public function reject(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersApprove) && $this->mayTouch($user, $target);
    }

    public function activate(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersManage) && $this->mayTouch($user, $target);
    }

    public function deactivate(User $user, User $target): bool
    {
        return $this->hasPermission($user, PermissionName::UsersManage) && $this->mayTouch($user, $target);
    }

    /**
     * Cambiar nombre y contrasena propios.
     */
    public function updateProfile(User $user, User $target): bool
    {
        return $user->is($target) && $user->canAccessApplication();
    }

    /**
     * Foto de perfil: la ve cualquier cuenta con acceso a la aplicacion (el chat esta abierto a todos los
     * usuarios activos), siempre que el dueno tambien siga activo. Sin foto o fuera de esto: 404.
     */
    public function viewAvatar(User $user, User $target): Response|bool
    {
        if (! $user->canAccessApplication()) {
            return false;
        }

        return $target->avatar_path !== null && ($user->is($target) || $target->canAccessApplication())
            ? true
            : Response::denyAsNotFound();
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

    /**
     * Una cuenta de administrador solo la gestiona quien tiene `admins.manage`.
     */
    private function mayTouch(User $user, User $target): bool
    {
        return ! $target->hasSystemRole(UserRole::Administrador)
            || $this->hasPermission($user, PermissionName::AdminsManage);
    }
}
