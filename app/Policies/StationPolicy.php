<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Station;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Estructura organizacional: solo quien tiene `org.manage` (administrador).
 */
class StationPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function view(User $user, Station $station): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function update(User $user, Station $station): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function delete(User $user, Station $station): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }
}
