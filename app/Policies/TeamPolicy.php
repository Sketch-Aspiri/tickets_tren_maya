<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Team;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Equipos: solo el jefe de zona (permiso `teams.manage`).
 */
class TeamPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::TeamsManage);
    }

    public function view(User $user, Team $team): bool
    {
        return $this->hasPermission($user, PermissionName::TeamsManage);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::TeamsManage);
    }

    public function update(User $user, Team $team): bool
    {
        return $this->hasPermission($user, PermissionName::TeamsManage);
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->hasPermission($user, PermissionName::TeamsManage);
    }
}
