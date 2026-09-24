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
 * - Jefe de zona: global (y puede acotar por equipo con el filtro).
 * - Coordinador: SOLO su equipo. Parte de `visibleTo` (que además le incluye lo que se le asignó fuera de su
 *   equipo) y lo restringe a su `team_id`: el panel mide el trabajo del equipo, no lo asignado a título personal.
 *   El filtro `team_id` que envíe se IGNORA aquí (el Form Request ya lo rechaza; esto es defensa en profundidad).
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
     * Equipo que efectivamente acota la consulta: el filtro (solo el jefe) o el propio del coordinador.
     */
    public function effectiveTeamId(User $user, DashboardFilters $filters): ?int
    {
        return match ($user->roleEnum()) {
            UserRole::JefeZona => $filters->teamId,
            UserRole::Coordinador => $user->team_id,
            default => null,
        };
    }

    /**
     * Equipos que puede elegir en el filtro: solo el jefe (el coordinador no ve selector).
     *
     * @return Collection<int, Team>
     */
    public function selectableTeams(User $user): Collection
    {
        if ($user->roleEnum() !== UserRole::JefeZona) {
            return new Collection;
        }

        return Team::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Personas que puede elegir en el filtro: el jefe, todas las activas con rol; el coordinador, solo los
     * integrantes activos de su equipo.
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
            UserRole::JefeZona => $query->get(['id', 'name']),
            UserRole::Coordinador => $user->team_id === null
                ? new Collection
                : $query->where('team_id', $user->team_id)->get(['id', 'name']),
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
     * @param  Builder<Ticket>|Builder<Activity>  $query
     */
    private function narrowByTeam(Builder $query, string $table, User $user, DashboardFilters $filters): void
    {
        $role = $user->roleEnum();

        if ($role !== UserRole::JefeZona && $role !== UserRole::Coordinador) {
            $query->whereIn("{$table}.id", []);

            return;
        }

        $teamId = $this->effectiveTeamId($user, $filters);

        if ($role === UserRole::Coordinador && $teamId === null) {
            $query->whereIn("{$table}.id", []);

            return;
        }

        $query->when($teamId, fn (Builder $q, int $id) => $q->where("{$table}.team_id", $id));
    }
}
