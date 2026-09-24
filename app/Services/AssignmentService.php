<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Assignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Asignación de tickets. Un ticket tiene 0 (bolsa del equipo) o más asignados, con EXACTAMENTE un
 * `responsable` y N `colaborador`. Asignar, reasignar y delegar son la misma operación (`assign`).
 *
 * Reglas (todas se revalidan aquí, bajo lock del ticket, aunque la Policy ya las haya comprobado):
 * - jefe: puede asignar a cualquier usuario ACTIVO con rol; coordinador: solo a activos de SU equipo;
 * - nunca a usuarios pendientes/inactivos/sin rol; nunca dos responsables; sin duplicar personas;
 * - los tickets finales (Completado/Cancelado) no se asignan: primero se reabren.
 */
final class AssignmentService
{
    private const LOG = 'tickets';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Usuarios a los que ESTE actor puede asignar el ticket (para el formulario).
     *
     * @return Collection<int, User>
     */
    public function assignableUsers(User $actor): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles')
            ->when(! $actor->hasSystemRole(UserRole::JefeZona), fn ($query) => $query->where('team_id', $actor->team_id))
            ->orderBy('name')
            ->get(['id', 'name', 'team_id']);
    }

    /**
     * Fija el conjunto completo de asignados: un responsable y los colaboradores indicados.
     *
     * @param  list<int>  $collaboratorIds
     */
    public function assign(User $actor, Ticket $ticket, int $responsibleId, array $collaboratorIds = []): Ticket
    {
        $collaboratorIds = array_values(array_unique(array_diff($collaboratorIds, [$responsibleId])));

        if (count($collaboratorIds) > (int) config('tickets.max_collaborators')) {
            throw BusinessRuleException::because('tickets.errors.too_many_collaborators', ['max' => (int) config('tickets.max_collaborators')]);
        }

        return DB::transaction(function () use ($actor, $ticket, $responsibleId, $collaboratorIds): Ticket {
            $locked = $this->lockOpenTicket($actor, $ticket, 'assign');
            $this->assertAssignable($actor, [$responsibleId, ...$collaboratorIds]);

            $before = $this->snapshot($locked);
            $desired = [$responsibleId => AssignmentRole::Responsable];

            foreach ($collaboratorIds as $collaboratorId) {
                $desired[$collaboratorId] = AssignmentRole::Colaborador;
            }

            $this->syncAssignments($locked, $actor, $desired);

            $this->audit->record(self::LOG, $before === [] ? 'assigned' : 'reassigned', $locked, $actor, ['assignments' => $before], ['assignments' => $this->snapshot($locked)], ['folio' => $locked->folio]);

            return $locked;
        });
    }

    /**
     * Devuelve el ticket a la bolsa del equipo (sin asignados).
     */
    public function unassign(User $actor, Ticket $ticket): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket): Ticket {
            $locked = $this->lockOpenTicket($actor, $ticket, 'assign');
            $before = $this->snapshot($locked);

            if ($before === []) {
                throw BusinessRuleException::because('tickets.errors.not_assigned');
            }

            $locked->assignments()->delete();

            $this->audit->record(self::LOG, 'unassigned', $locked, $actor, ['assignments' => $before], ['assignments' => []], ['folio' => $locked->folio]);

            return $locked;
        });
    }

    /**
     * "Tomar" un ticket de la bolsa. Atómico: la fila del ticket se bloquea (`lockForUpdate`) y se
     * comprueba que siga sin asignar, así que si dos usuarios lo toman a la vez solo uno gana.
     */
    public function take(User $actor, Ticket $ticket): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket): Ticket {
            $locked = $this->lockOpenTicket($actor, $ticket, 'take');

            if ($locked->assignments()->exists()) {
                throw BusinessRuleException::because('tickets.errors.already_taken');
            }

            $locked->assignments()->save(new Assignment([
                'user_id' => $actor->getKey(),
                'role' => AssignmentRole::Responsable,
                'assigned_by' => $actor->getKey(),
            ]));

            $this->audit->record(self::LOG, 'taken', $locked, $actor, ['assignments' => []], ['assignments' => $this->snapshot($locked)], ['folio' => $locked->folio]);

            return $locked;
        });
    }

    /**
     * Bloquea el ticket, revalida la autorización sobre la fila fresca y rechaza tickets finales.
     */
    private function lockOpenTicket(User $actor, Ticket $ticket, string $ability): Ticket
    {
        $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

        Gate::forUser($actor)->authorize($ability, $locked);

        if ($locked->status->isFinal()) {
            throw BusinessRuleException::because('tickets.errors.closed_assignment');
        }

        return $locked;
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertAssignable(User $actor, array $userIds): void
    {
        $users = User::query()->with('roles:id,name')->whereKey($userIds)->get();

        if ($users->count() !== count($userIds)) {
            throw BusinessRuleException::because('tickets.errors.invalid_assignee');
        }

        foreach ($users as $candidate) {
            if (! $candidate->canAccessApplication()) {
                throw BusinessRuleException::because('tickets.errors.invalid_assignee');
            }

            if (! $actor->hasSystemRole(UserRole::JefeZona) && (int) $candidate->team_id !== (int) $actor->team_id) {
                throw BusinessRuleException::because('tickets.errors.assignee_out_of_team');
            }
        }
    }

    /**
     * Aplica el conjunto deseado conservando las asignaciones que no cambian.
     *
     * @param  array<int, AssignmentRole>  $desired  user_id => rol
     */
    private function syncAssignments(Ticket $ticket, User $actor, array $desired): void
    {
        $current = $ticket->assignments()->get()->keyBy('user_id');

        $ticket->assignments()->whereNotIn('user_id', array_keys($desired))->delete();

        foreach ($desired as $userId => $role) {
            $existing = $current->get($userId);

            if ($existing === null) {
                $ticket->assignments()->save(new Assignment(['user_id' => $userId, 'role' => $role, 'assigned_by' => $actor->getKey()]));

                continue;
            }

            if ($existing->role !== $role) {
                $existing->update(['role' => $role, 'assigned_by' => $actor->getKey()]);
            }
        }
    }

    /**
     * @return list<array{user_id: int, role: string}>
     */
    private function snapshot(Ticket $ticket): array
    {
        return $ticket->assignments()
            ->orderBy('id')
            ->get(['user_id', 'role'])
            ->map(fn (Assignment $assignment): array => ['user_id' => $assignment->user_id, 'role' => $assignment->role->value])
            ->all();
    }
}
