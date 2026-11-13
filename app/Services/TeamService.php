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
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginate(User $actor): LengthAwarePaginator
    {
        return Team::query()
            ->manageableBy($actor)
            ->with(['coordinator:id,name', 'additionalCoordinators:id,name', 'coordination.station'])
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
     * `coordinator_id` es el coordinador PRINCIPAL; `additional_coordinator_ids` los demas (decision 96). Un usuario
     * nunca es principal y adicional a la vez, y sin principal no hay adicionales (la request lo exige).
     *
     * @param  array{name: string, coordination_id: int, coordinator_id?: ?int, additional_coordinator_ids?: array<int, int|string>}  $data
     */
    public function update(Team $team, array $data): Team
    {
        return DB::transaction(function () use ($team, $data): Team {
            $principalId = isset($data['coordinator_id']) ? (int) $data['coordinator_id'] : null;

            $team->update([
                'coordination_id' => $data['coordination_id'],
                'name' => $data['name'],
                'coordinator_id' => $principalId,
            ]);

            // Solo se toca el conjunto adicional si la peticion lo trae (otras llamadas no lo borran sin querer).
            if (array_key_exists('additional_coordinator_ids', $data)) {
                $wanted = $principalId === null
                    ? []
                    : array_values(array_diff(array_unique(array_map('intval', (array) $data['additional_coordinator_ids'])), [$principalId]));

                $this->syncAdditional($team, $wanted);
            }

            return $team;
        });
    }

    /**
     * @param  list<int>  $wanted
     */
    private function syncAdditional(Team $team, array $wanted): void
    {
        $before = $team->additionalCoordinators()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all();
        $team->additionalCoordinators()->sync($wanted);

        sort($before);
        $after = $wanted;
        sort($after);

        if ($before !== $after) {
            $this->audit->record('teams', 'coordinators_changed', $team, request()->user(), ['additional_coordinators' => $before], ['additional_coordinators' => $after]);
        }

        $team->unsetRelation('additionalCoordinators');
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

        // Coordinador principal: se libera; si el equipo tenia adicionales, el primero valido pasa a principal para
        // que el equipo no se quede sin responsable de las actividades automaticas.
        Team::query()
            ->where('coordinator_id', $user->getKey())
            ->get()
            ->reject(fn (Team $team): bool => $team->canBeCoordinatedBy($user))
            ->each(function (Team $team) use ($user): void {
                $successor = $team->additionalCoordinators()
                    ->where('users.id', '!=', $user->getKey())
                    ->get()
                    ->first(fn (User $candidate): bool => $team->canBeCoordinatedBy($candidate));

                if ($successor !== null) {
                    $team->additionalCoordinators()->detach($successor->getKey());
                }

                $team->update(['coordinator_id' => $successor?->getKey()]);
            });

        // Coordinador adicional que ya no corresponde: se quita.
        Team::query()
            ->whereHas('additionalCoordinators', fn ($query) => $query->where('users.id', $user->getKey()))
            ->get()
            ->reject(fn (Team $team): bool => $team->canBeCoordinatedBy($user))
            ->each(fn (Team $team) => $team->additionalCoordinators()->detach($user->getKey()));
    }
}
