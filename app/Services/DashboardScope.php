<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Coordination;
use App\Models\Station;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Dashboard\DashboardFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ÚNICA definición del alcance del panel de seguimiento (sección 11 de CLAUDE.md):
 *
 * - Administrador: global (y puede acotar por equipo con el filtro). Jefe de zona: los equipos de las coordinaciones
 *   que supervisa, con la misma regla que el coordinador.
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
        $teamIds = match ($user->roleEnum()) {
            UserRole::Administrador => $filters->teamId === null ? null : [$filters->teamId],
            UserRole::JefeZona, UserRole::Coordinador => $this->narrowedTeamIds($user->workingTeamIds(), $filters),
            default => [],
        };

        return $this->narrowByOrganization($teamIds, $filters);
    }

    /**
     * Estaciones que puede elegir en el filtro: todas (administrador) o las de sus coordinaciones si son varias.
     *
     * @return Collection<int, Station>
     */
    public function selectableStations(User $user): Collection
    {
        $stations = Station::query()
            ->when(! $user->seesAllTeams(), fn ($query) => $query->whereIn('id', Coordination::query()->select('station_id')->whereIn('id', $user->coordinationIdsQuery())))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $user->seesAllTeams() || $stations->count() > 1 ? $stations : new Collection;
    }

    /**
     * Coordinaciones que puede elegir en el filtro: todas (administrador) o las suyas si son varias.
     *
     * @return Collection<int, Coordination>
     */
    public function selectableCoordinations(User $user): Collection
    {
        $coordinations = Coordination::query()
            ->with('station:id,name')
            ->when(! $user->seesAllTeams(), fn ($query) => $query->whereIn('id', $user->coordinationIdsQuery()))
            ->orderBy('name')
            ->get(['id', 'station_id', 'name']);

        return $user->seesAllTeams() || $coordinations->count() > 1 ? $coordinations : new Collection;
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
            // Con sus equipos (y coordinacion/edificio) para agruparlas y filtrarlas con la cascada del panel.
            ->with(['teams:id,name,coordination_id', 'teams.coordination:id,name,station_id', 'teams.coordination.station:id,name'])
            ->orderBy('name');

        return match ($user->roleEnum()) {
            UserRole::Administrador => $query->get(['id', 'name']),
            UserRole::JefeZona, UserRole::Coordinador => $query->memberOfAny($user->workingTeamIds())->get(['id', 'name']),
            default => new Collection,
        };
    }

    /**
     * @return Collection<int, Category>
     */
    public function selectableCategories(User $user): Collection
    {
        return Category::query()
            ->visibleTo($user)
            ->with(['coordination:id,name,station_id', 'coordination.station:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'active', 'coordination_id']);
    }

    /**
     * Estacion y coordinacion estrechan los equipos ya permitidos (nunca los amplian). Sin ninguno de los dos
     * filtros no cambia nada.
     *
     * @param  list<int>|null  $teamIds
     * @return list<int>|null
     */
    private function narrowByOrganization(?array $teamIds, DashboardFilters $filters): ?array
    {
        if ($filters->coordinationId === null && $filters->stationId === null) {
            return $teamIds;
        }

        return Team::query()
            ->when($filters->coordinationId, fn (Builder $q, int $id) => $q->where('teams.coordination_id', $id))
            ->when($filters->stationId, fn (Builder $q, int $id) => $q->whereIn('teams.coordination_id', Coordination::query()->select('id')->where('station_id', $id)))
            ->when($teamIds !== null, fn (Builder $q) => $q->whereIn('teams.id', $teamIds ?? []))
            ->pluck('teams.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * El filtro de equipo solo puede estrechar al conjunto propio: uno ajeno se ignora.
     *
     * @param  list<int>  $own
     * @return list<int>
     */
    private function narrowedTeamIds(array $own, DashboardFilters $filters): array
    {
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
