<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de equipos. La bitacora de create/update/delete la genera el trait
 * LogsActivity del modelo Team.
 *
 * Invariante de coordinacion (definida en Team::canBeCoordinatedBy): un coordinador solo
 * coordina el equipo al que pertenece y nunca mas de uno. Las peticiones la validan con
 * ActiveCoordinator; los cambios de usuario la restablecen con releaseInvalidCoordination.
 */
final class TeamService
{
    /**
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginate(User $actor): LengthAwarePaginator
    {
        return Team::query()
            ->manageableBy($actor)
            ->with(['coordinator:id,name', 'coordination.station'])
            ->withCount('members')
            // Agrupado por edificio y coordinacion (la vista pone un encabezado por coordinacion), luego por nombre.
            ->orderBy(DB::table('coordinations')->join('stations', 'stations.id', '=', 'coordinations.station_id')->select('stations.name')->whereColumn('coordinations.id', 'teams.coordination_id'))
            ->orderBy(DB::table('coordinations')->select('name')->whereColumn('coordinations.id', 'teams.coordination_id'))
            ->orderBy('teams.coordination_id')
            ->orderBy('teams.name')
            ->paginate((int) config('tickets.users_per_page'));
    }

    /**
     * Un equipo nuevo no tiene integrantes, asi que nace sin coordinador (ver ActiveCoordinator).
     *
     * @param  array{name: string, coordination_id: int}  $data
     */
    public function create(array $data): Team
    {
        return Team::query()->create([
            'coordination_id' => $data['coordination_id'],
            'name' => $data['name'],
            'coordinator_id' => null,
        ]);
    }

    /**
     * @param  array{name: string, coordination_id: int, coordinator_id?: ?int}  $data
     */
    public function update(Team $team, array $data): Team
    {
        $team->update([
            'coordination_id' => $data['coordination_id'],
            'name' => $data['name'],
            'coordinator_id' => $data['coordinator_id'] ?? null,
        ]);

        return $team;
    }

    public function delete(Team $team): void
    {
        if ($team->members()->exists()) {
            throw BusinessRuleException::because('teams.errors.has_members');
        }

        // Los tickets (incluso los eliminados) conservan su equipo: no se puede borrar un equipo con historial.
        if (Ticket::withTrashed()->where('team_id', $team->getKey())->exists()) {
            throw BusinessRuleException::because('teams.errors.has_tickets');
        }

        $team->delete();
    }

    /**
     * Libera los equipos que el usuario aun figura coordinando pero ya no le corresponden
     * (cambio de equipo, de rol o inactivacion). Llamar despues de persistir el cambio.
     */
    public function releaseInvalidCoordination(User $user): void
    {
        $user->unsetRelation('roles');

        Team::query()
            ->where('coordinator_id', $user->getKey())
            ->get()
            ->reject(fn (Team $team): bool => $team->canBeCoordinatedBy($user))
            ->each(fn (Team $team) => $team->update(['coordinator_id' => null]));
    }
}
