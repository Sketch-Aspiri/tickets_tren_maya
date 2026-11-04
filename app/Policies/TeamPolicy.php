<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Team;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Equipos: administrador (todos) y jefe de zona (solo los de sus coordinaciones), con el permiso `teams.manage`.
 * Un equipo fuera de las coordinaciones del jefe responde 404.
 */
class TeamPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::TeamsManage);
    }

    public function view(User $user, Team $team): Response|bool
    {
        return $this->scoped($user, $team);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::TeamsManage);
    }

    public function update(User $user, Team $team): Response|bool
    {
        return $this->scoped($user, $team);
    }

    public function delete(User $user, Team $team): Response|bool
    {
        return $this->scoped($user, $team);
    }

    private function scoped(User $user, Team $team): Response|bool
    {
        if (! $this->hasPermission($user, PermissionName::TeamsManage)) {
            return false;
        }

        $inScope = $user->seesAllTeams() || in_array((int) $team->coordination_id, $user->coordinationIds(), true);

        return $inScope ? true : Response::denyAsNotFound();
    }
}
