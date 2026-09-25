<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Dashboard\DashboardFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ÚNICA definición del alcance del panel de seguimiento (sección 11 de CLAUDE.md):
 *
 * - Administrador y jefe de zona: global (y pueden acotar por equipo con el filtro).
 * - Coordinador: SOLO sus equipos (pertenece a uno o varios). Parte de `visibleTo` (que además le incluye lo que se
 *   le asignó fuera de sus equipos) y lo restringe a los `team_user` propios: el panel mide el trabajo de los
 *   equipos, no lo asignado a título personal. El filtro `team_id` puede estrechar a uno de sus equipos; si envía
 *   uno ajeno se IGNORA aquí (el Form Request ya lo rechaza; esto es defensa en profundidad).
 * - Empleado, pendiente, inactivo o sin rol: nada (además la Policy responde 403 antes de llegar aquí).
 *
 * Los filtros (equipo, empleado, categoría) solo pueden ESTRECHAR el conjunto resultante: la consulta siempre
 * parte de `visibleTo`. Las plantillas de recurrencia no cuentan (su trabajo lo hacen sus instancias) y los
 * registros eliminados (soft delete) quedan fuera por el alcance global del modelo.
 */
final class DashboardScope
{
    /**
     * @return Builder<Ticket>
     */
    public function tickets(User $user, DashboardFilters $filters): Builder
    {
        $query = Ticket::query()->visibleTo($user);

        $this->narrowByTeam($query, 'tickets', $user, $filters);

        return $query
            ->when($filters->categoryId, fn (Builder $q, int $id) => $q->where('tickets.category_id', $id))
            ->when($filters->userId, fn (Builder $q, int $id) => $q->whereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $id)));
    }

    /**
     * @return Builder<Activity>
     */
    public function activities(User $user, DashboardFilters $filters): Builder
    {
        $query = Activity::query()->visibleTo($user)->withoutTemplates();

        $this->narrowByTeam($query, 'activities', $user, $filters);

        return $query
            ->when($filters->categoryId, fn (Builder $q, int $id) => $q->where('activities.category_id', $id))
            ->when($filters->userId, fn (Builder $q, int $id) => $q->whereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $id)));
    }

    /**
     * Equipos que efectivamente acotan la consulta. `null` = sin restricción de equipo (alcance global sin filtro);
     * lista vacía = ninguno (coordinador sin equipos, o un rol sin panel).
     *
     * @return list<int>|null
     */
    public function effectiveTeamIds(User $user, DashboardFilters $filters): ?array
    {
        return match ($user->roleEnum()) {
            UserRole::Administrador, UserRole::JefeZona => $filters->teamId === null ? null : [$filters->teamId],
            UserRole::Coordinador => $this->coordinatorTeamIds($user, $filters),
            default => [],
        };
    }

    /**
     * Equipos que puede elegir en el filtro: todos (alcance global) o los suyos si el coordinador tiene varios
     * (con uno solo no hay nada que elegir).
     *
     * @return Collection<int, Team>
     */
    public function selectableTeams(User $user): Collection
    {
        return match ($user->roleEnum()) {
            UserRole::Administrador, UserRole::JefeZona, UserRole::Coordinador => $user->selectableTeams(),
            default => new Collection,
        };
    }

    /**
     * Personas que puede elegir en el filtro: alcance global, todas las activas con rol; el coordinador, solo los
     * integrantes activos de sus equipos.
     *
     * @return Collection<int, User>
     */
    public function selectableEmployees(User $user): Collection
    {
        $query = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles')
            ->orderBy('name');

        return match ($user->roleEnum()) {
            UserRole::Administrador, UserRole::JefeZona => $query->get(['id', 'name']),
            UserRole::Coordinador => $query->memberOfAny($user->teamIds())->get(['id', 'name']),
            default => new Collection,
        };
    }

    /**
     * @return Collection<int, Category>
     */
    public function selectableCategories(): Collection
    {
        return Category::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return list<int>
     */
    private function coordinatorTeamIds(User $user, DashboardFilters $filters): array
    {
        $own = $user->teamIds();

        return $filters->teamId !== null && in_array($filters->teamId, $own, true) ? [$filters->teamId] : $own;
    }

    /**
     * @param  Builder<Ticket>|Builder<Activity>  $query
     */
    private function narrowByTeam(Builder $query, string $table, User $user, DashboardFilters $filters): void
    {
        $teamIds = $this->effectiveTeamIds($user, $filters);

        if ($teamIds === null) {
            return;
        }

        $query->whereIn("{$table}.team_id", $teamIds);
    }
}
