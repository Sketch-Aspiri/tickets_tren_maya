<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AssignmentRole;
use App\Enums\PermissionName;
use App\Models\Activity;
use App\Models\Subtask;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Autorización de subtareas. Heredan el ALCANCE de su actividad (fuera de alcance responde 404).
 *
 * - Crear, editar y eliminar: quien gestiona la actividad (jefe / coordinador del alcance).
 * - Marcar hecha/no hecha (`markDone`): quien gestiona; el responsable de la actividad (cualquier subtarea,
 *   porque responde por su avance) o la persona a la que se asignó ESA subtarea. Un colaborador sin subtarea
 *   propia no marca las ajenas. Que la actividad esté final o sea plantilla lo valida SubtaskService.
 */
class SubtaskPolicy
{
    use ChecksPermissions;

    public function create(User $user, Activity $activity): Response|bool
    {
        return $this->inScope($user, $activity, fn (): bool => $this->canManage($user));
    }

    public function update(User $user, Subtask $subtask): Response|bool
    {
        return $this->inScope($user, $subtask->activity, fn (): bool => $this->canManage($user));
    }

    public function delete(User $user, Subtask $subtask): Response|bool
    {
        return $this->update($user, $subtask);
    }

    public function markDone(User $user, Subtask $subtask): Response|bool
    {
        return $this->inScope($user, $subtask->activity, fn (): bool => $this->hasPermission($user, PermissionName::ActivitiesWork)
            && ($this->canManage($user)
                || (int) $subtask->assigned_to === (int) $user->getKey()
                || $this->isResponsible($user, $subtask->activity)));
    }

    private function canManage(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::ActivitiesManage);
    }

    private function isResponsible(User $user, Activity $activity): bool
    {
        return $activity->assignments()
            ->where('user_id', $user->getKey())
            ->where('role', AssignmentRole::Responsable->value)
            ->exists();
    }

    /**
     * @param  Closure(): bool  $rule
     */
    private function inScope(User $user, Activity $activity, Closure $rule): Response|bool
    {
        $view = Gate::forUser($user)->inspect('view', $activity);

        return $view->allowed() ? $rule() : $view;
    }
}
