<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Gestion de usuarios por el jefe de zona: listado, aprobacion, estado, rol y equipo.
 * Toda regla de integridad vive aqui, no en controladores ni vistas.
 */
final class UserService
{
    private const LOG = 'users';

    /** Intentos ante interbloqueos de MySQL antes de pedir al usuario que reintente. */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TeamService $teams,
        private readonly SessionInvalidator $sessions,
    ) {}

    /**
     * @param  array{status?: ?string, role?: ?string, team_id?: ?int, q?: ?string}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles:id,name', 'team:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => $q->role($role))
            ->when($filters['team_id'] ?? null, fn (Builder $q, int $teamId) => $q->where('team_id', $teamId))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $this->applySearch($q, $term))
            ->latest('id')
            ->paginate($perPage ?? (int) config('tickets.users_per_page'))
            ->withQueryString();
    }

    public function pendingCount(): int
    {
        return User::query()->where('status', UserStatus::Pending->value)->count();
    }

    /**
     * Aprueba una cuenta pendiente (o re-evalua una rechazada) asignando rol y equipo.
     */
    public function approve(User $actor, User $target, UserRole $role, ?int $teamId): User
    {
        if ($target->isActive()) {
            throw BusinessRuleException::because('users.errors.already_active');
        }

        $this->assertTeamRequirement($role, $teamId);
        $teamId = $this->teamIdFor($role, $teamId);

        return DB::transaction(function () use ($actor, $target, $role, $teamId): User {
            $before = $this->snapshot($target);

            $this->persist($target, ['status' => UserStatus::Active, 'team_id' => $teamId]);
            $target->syncRoles([$role->value]);

            $this->audit->record(self::LOG, 'approved', $target, $actor, $before, $this->snapshot($target));

            return $target;
        });
    }

    public function reject(User $actor, User $target, ?string $reason = null): User
    {
        if (! $target->isPending()) {
            throw BusinessRuleException::because('users.errors.not_pending');
        }

        return DB::transaction(function () use ($actor, $target, $reason): User {
            $before = $this->snapshot($target);
            $this->persist($target, ['status' => UserStatus::Inactive]);
            $this->sessions->revokeAll($target);

            $this->audit->record(self::LOG, 'rejected', $target, $actor, $before, $this->snapshot($target), ['reason' => $reason]);

            return $target;
        });
    }

    public function activate(User $actor, User $target): User
    {
        if (! $target->isInactive()) {
            throw BusinessRuleException::because('users.errors.not_inactive');
        }

        $role = $target->roleEnum();

        if ($role === null || ($role->requiresTeam() && $target->team_id === null)) {
            throw BusinessRuleException::because('users.errors.needs_role_and_team');
        }

        return DB::transaction(function () use ($actor, $target): User {
            $before = $this->snapshot($target);
            $this->persist($target, ['status' => UserStatus::Active]);
            // Ninguna sesion anterior (ni su verificacion 2FA) debe "resucitar" con la reactivacion.
            $this->sessions->revokeAll($target);

            $this->audit->record(self::LOG, 'activated', $target, $actor, $before, $this->snapshot($target));

            return $target;
        });
    }

    public function deactivate(User $actor, User $target, ?string $reason = null): User
    {
        if (! $target->isActive()) {
            throw BusinessRuleException::because('users.errors.not_active');
        }

        if ($actor->is($target)) {
            throw BusinessRuleException::because('users.errors.cannot_deactivate_self');
        }

        return $this->transactionWithRetry(function () use ($actor, $target, $reason): User {
            $this->assertNotLastActiveJefe($target);

            $before = $this->snapshot($target);
            $this->persist($target, ['status' => UserStatus::Inactive]);
            $this->teams->releaseInvalidCoordination($target);
            $this->sessions->revokeAll($target);

            $this->audit->record(self::LOG, 'deactivated', $target, $actor, $before, $this->snapshot($target), ['reason' => $reason]);

            return $target;
        });
    }

    /**
     * Cambia rol y/o equipo de un usuario activo.
     */
    public function updateRoleAndTeam(User $actor, User $target, UserRole $role, ?int $teamId): User
    {
        if (! $target->isActive()) {
            throw BusinessRuleException::because('users.errors.not_active');
        }

        $this->assertTeamRequirement($role, $teamId);
        $teamId = $this->teamIdFor($role, $teamId);

        return $this->transactionWithRetry(function () use ($actor, $target, $role, $teamId): User {
            $oldRole = $target->roleEnum();
            $roleChanged = $oldRole !== $role;

            if ($roleChanged) {
                $this->enforceRoleChangeRules($actor, $target, $oldRole);
            }

            $before = $this->snapshot($target);

            $this->persist($target, ['team_id' => $teamId]);

            if ($roleChanged) {
                $target->syncRoles([$role->value]);
            }

            // Un coordinador que cambia de equipo o de rol deja de coordinar lo que ya no le corresponde.
            $this->teams->releaseInvalidCoordination($target->refresh());

            $after = $this->snapshot($target);

            if ($roleChanged || $before['team_id'] !== $after['team_id']) {
                $this->audit->record(self::LOG, $roleChanged ? 'role_changed' : 'team_changed', $target, $actor, $before, $after);
            }

            return $target;
        });
    }

    private function enforceRoleChangeRules(User $actor, User $target, ?UserRole $oldRole): void
    {
        if ($oldRole !== UserRole::JefeZona) {
            return;
        }

        if ($actor->is($target)) {
            throw BusinessRuleException::because('users.errors.cannot_change_own_role');
        }

        $this->assertNotLastActiveJefe($target);
    }

    /**
     * El sistema nunca puede quedarse sin un jefe de zona activo. Se bloquea el conjunto completo
     * de jefes activos (ordenado por id, para que dos transacciones tomen los bloqueos en el mismo
     * orden) y se cuenta despues del bloqueo, de modo que dos bajas simultaneas no dejen cero.
     */
    private function assertNotLastActiveJefe(User $target): void
    {
        if (! $target->isActive() || ! $target->hasSystemRole(UserRole::JefeZona)) {
            return;
        }

        $activeJefeIds = User::query()
            ->activeWithRole(UserRole::JefeZona)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeJefeIds->reject(fn (mixed $id): bool => (int) $id === (int) $target->getKey())->isEmpty()) {
            throw BusinessRuleException::because('users.errors.last_jefe');
        }
    }

    /**
     * Transaccion con reintentos ante interbloqueo; si persiste, mensaje de reintento (no un 500).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function transactionWithRetry(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback, self::TRANSACTION_ATTEMPTS);
        } catch (PDOException $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            throw BusinessRuleException::because('users.errors.concurrent_change');
        }
    }

    private function assertTeamRequirement(UserRole $role, ?int $teamId): void
    {
        if ($role->requiresTeam() && $teamId === null) {
            throw BusinessRuleException::because('users.errors.team_required');
        }
    }

    /**
     * El jefe de zona ve todo y no pertenece a ningun equipo: nunca conserva un `team_id` residual.
     */
    private function teamIdFor(UserRole $role, ?int $teamId): ?int
    {
        return $role->requiresTeam() ? $teamId : null;
    }

    /**
     * status/team_id no son asignables en masa: se fijan aqui de forma explicita.
     * El registro generico del modelo se suprime porque se emite un evento explicito.
     *
     * @param  array{status?: UserStatus, team_id?: ?int}  $attributes
     */
    private function persist(User $target, array $attributes): void
    {
        $target->disableLogging();
        $target->forceFill($attributes)->save();
        $target->enableLogging();
    }

    /**
     * @return array{status: string, role: ?string, team_id: ?int}
     */
    private function snapshot(User $user): array
    {
        $user->unsetRelation('roles');

        return [
            'status' => $user->status->value,
            'role' => $user->roleEnum()?->value,
            'team_id' => $user->team_id,
        ];
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '\\%_');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner->where('name', 'like', "%{$escaped}%")
                ->orWhere('email', 'like', "%{$escaped}%");
        });
    }
}
