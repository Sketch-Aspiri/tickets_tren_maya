<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Coordination;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

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
}
