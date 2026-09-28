<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Closure;
use Illuminate\Auth\Access\Response;

/**
 * Autorización de actividades = PERMISO de Spatie (qué acciones tiene el rol) + ALCANCE (qué actividades ve
 * el usuario, `Activity::scopeVisibleTo`). Una actividad fuera del alcance responde 404 (no revela que
 * existe); una visible pero sin permiso para la acción responde 403.
 *
 * Solo jefe (todas) y coordinador (su equipo) crean, editan, eliminan y asignan; el empleado ve las que se le
 * asignaron y avanza hasta En revisión lo suyo. La MÁQUINA de estados vive en TicketStatus (compartida con
 * los tickets) y se aplica en StatusTransitioner; aquí solo se decide QUIÉN recorre cada transición.
 */
class ActivityPolicy
{
    use ChecksPermissions;

    /**
     * El listado de gestión (y su menú) es para quien gestiona; el empleado llega a lo suyo por "Mis
     * pendientes" y por el detalle.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::ActivitiesManage);
    }

    /**
     * Exportar el listado filtrado a Excel (jefe y coordinador, igual que el propio listado). Las FILAS las
     * acota `Activity::visibleTo`; el permiso solo habilita la accion.
     */
    public function export(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::ExportsCreate)
            && $this->hasPermission($user, PermissionName::ActivitiesManage);
    }

    public function view(User $user, Activity $activity): Response|bool
    {
        return $this->scoped($user, $activity, fn (): bool => true);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::ActivitiesCreate);
    }

    /**
     * Editar cualquier actividad no final del alcance (plantillas e instancias incluidas).
     */
    public function update(User $user, Activity $activity): Response|bool
    {
        return $this->scoped($user, $activity, fn (): bool => ! $activity->status->isFinal()
            && $this->hasPermission($user, PermissionName::ActivitiesManage));
    }

    public function delete(User $user, Activity $activity): Response|bool
    {
        return $this->scoped($user, $activity, fn (): bool => $this->hasPermission($user, PermissionName::ActivitiesManage));
    }

    /**
     * Restaurar una plantilla eliminada (papelera de `/activities/templates`). NO usa `scoped()`/`canSee()`:
     * esas dos parten de `Activity::query()->visibleTo($user)`, que por el scope global de SoftDeletes nunca
     * encuentra una fila eliminada. Aquí se encadena `withTrashed()` ANTES de `visibleTo()` a propósito.
     * Fuera de alcance responde 404 (igual que el resto de la Policy; el alcance del empleado ya excluye
     * las plantillas por completo, así que nunca llega a comprobar el permiso). Una actividad visible que
     * NO es plantilla responde 403 (existe y está en su alcance, pero esta papelera es exclusiva de
     * plantillas): la Policy comprueba `isTemplate()` por sí misma para que la autorización sea autosuficiente
     * y nunca dependa solo de que `ActivityService::restore()` lance la excepción de negocio.
     */
    public function restore(User $user, Activity $activity): Response|bool
    {
        if (! Activity::withTrashed()->visibleTo($user)->whereKey($activity->getKey())->exists()) {
            return Response::denyAsNotFound();
        }

        if (! $this->hasPermission($user, PermissionName::ActivitiesManage)) {
            return Response::deny();
        }

        if (! $activity->isTemplate()) {
            return Response::deny(__('activities.errors.restore_not_template'));
        }

        return Response::allow();
    }

    /**
     * Cambiar la actividad al estado `$to`. Quién puede recorrer cada arista (igual que en tickets):
     * - cancelar y reabrir (volver a Pendiente): gestión (jefe / coordinador del alcance);
     * - aprobar (-> Completado) y rechazar (En revisión -> En proceso): revisión (jefe / coordinador);
     * - avanzar hasta En revisión: quien gestiona, o el empleado solo en lo suyo (asignado).
     */
    public function transition(User $user, Activity $activity, TicketStatus $to): Response|bool
    {
        return $this->scoped($user, $activity, function () use ($user, $activity, $to): bool {
            $from = $activity->status;

            if (in_array($to, [TicketStatus::Cancelled, TicketStatus::Pending], true) || $from->isFinal()) {
                return $this->hasPermission($user, PermissionName::ActivitiesManage);
            }

            if ($to === TicketStatus::Completed || ($from === TicketStatus::InReview && $to === TicketStatus::InProgress)) {
                return $this->hasPermission($user, PermissionName::ActivitiesReview);
            }

            return $this->hasPermission($user, PermissionName::ActivitiesWork)
                && ($this->hasPermission($user, PermissionName::ActivitiesManage) || $activity->isInvolving($user));
        });
    }

    /**
     * Asignar y reasignar (el alcance ya limita al coordinador a su equipo). Que los asignados sean activos
     * y del equipo correcto lo impone AssignmentService.
     */
    public function assign(User $user, Activity $activity): Response|bool
    {
        return $this->scoped($user, $activity, fn (): bool => $this->hasPermission($user, PermissionName::ActivitiesAssign));
    }

    public function comment(User $user, Activity $activity): Response|bool
    {
        return $this->scoped($user, $activity, fn (): bool => $this->hasPermission($user, PermissionName::ActivitiesWork));
    }

    public function attach(User $user, Activity $activity): Response|bool
    {
        return $this->comment($user, $activity);
    }

    /**
     * @param  Closure(): bool  $rule
     */
    private function scoped(User $user, Activity $activity, Closure $rule): Response|bool
    {
        if (! $this->canSee($user, $activity)) {
            return Response::denyAsNotFound();
        }

        return $rule();
    }

    /**
     * Misma consulta de alcance que los listados: una sola definición evita IDOR por divergencia.
     */
    private function canSee(User $user, Activity $activity): bool
    {
        return $this->hasPermission($user, PermissionName::ActivitiesView)
            && Activity::query()->visibleTo($user)->whereKey($activity->getKey())->exists();
    }
}
