<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Gestion de usuarios por el administrador y el jefe de zona: listado, aprobacion, estado, rol y equipos.
 * Toda regla de integridad vive aqui, no en controladores ni vistas.
 *
 * Un usuario de cualquier rol puede pertenecer a varios equipos (`team_user`). Coordinadores y empleados
 * necesitan al menos uno; administrador y jefe de zona lo tienen opcional (ven todo de todos modos).
 * Las cuentas y el rol de administrador solo los gestiona quien tiene `admins.manage` (el administrador).
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
            ->with(['roles:id,name', 'teams:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => $q->role($role))
            ->when($filters['team_id'] ?? null, fn (Builder $q, int $teamId) => $q->memberOfAny([$teamId]))
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
     * Aprueba una cuenta pendiente (o re-evalua una rechazada) asignando rol y equipos.
     *
     * @param  list<int>  $teamIds
     */
    public function approve(User $actor, User $target, UserRole $role, array $teamIds): User
    {
        if ($target->isActive()) {
            throw BusinessRuleException::because('users.errors.already_active');
        }

        $teamIds = $this->normalizeTeamIds($role, $teamIds);
        $this->assertCanAssignRole($actor, $target, $role);

        return DB::transaction(function () use ($actor, $target, $role, $teamIds): User {
            $before = $this->snapshot($target);

            $this->persist($target, ['status' => UserStatus::Active]);
            $target->teams()->sync($teamIds);
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

        if ($role === null || ($role->requiresTeam() && ! $target->teams()->exists())) {
            throw BusinessRuleException::because('users.errors.needs_role_and_team');
        }

        $this->assertCanAssignRole($actor, $target, $role);

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

        $this->assertCanAssignRole($actor, $target, $target->roleEnum());

        return $this->transactionWithRetry(function () use ($actor, $target, $reason): User {
            $this->assertNotLastActiveOfRole($target);

            $before = $this->snapshot($target);
            $this->persist($target, ['status' => UserStatus::Inactive]);
            $this->teams->releaseInvalidCoordination($target);
            $this->sessions->revokeAll($target);

            $this->audit->record(self::LOG, 'deactivated', $target, $actor, $before, $this->snapshot($target), ['reason' => $reason]);

            return $target;
        });
    }

    /**
     * Cambia rol y/o equipos de un usuario activo.
     *
     * @param  list<int>  $teamIds
     */
    public function updateRoleAndTeams(User $actor, User $target, UserRole $role, array $teamIds): User
    {
        if (! $target->isActive()) {
            throw BusinessRuleException::because('users.errors.not_active');
        }

        $teamIds = $this->normalizeTeamIds($role, $teamIds);
        $this->assertCanAssignRole($actor, $target, $role);

        return $this->transactionWithRetry(function () use ($actor, $target, $role, $teamIds): User {
            $oldRole = $target->roleEnum();
            $roleChanged = $oldRole !== $role;

            if ($roleChanged) {
                $this->enforceRoleChangeRules($actor, $target, $oldRole);
            }

            $before = $this->snapshot($target);

            $target->teams()->sync($teamIds);

            if ($roleChanged) {
                $target->syncRoles([$role->value]);
            }

            // Un coordinador que cambia de rol o deja un equipo deja de coordinar lo que ya no le corresponde.
            $this->teams->releaseInvalidCoordination($target->refresh());

            $after = $this->snapshot($target);

            if ($roleChanged || $before['team_ids'] !== $after['team_ids']) {
                $this->audit->record(self::LOG, $roleChanged ? 'role_changed' : 'team_changed', $target, $actor, $before, $after);
            }

            return $target;
        });
    }

    /**
     * Solo quien tiene `admins.manage` (el administrador) otorga el rol de administrador o gestiona una cuenta que
     * ya lo es (aprobarla, cambiarle el rol, activarla o inactivarla). Un jefe de zona no puede.
     */
    private function assertCanAssignRole(User $actor, User $target, ?UserRole $role): void
    {
        $touchesAdmin = $role === UserRole::Administrador || $target->hasSystemRole(UserRole::Administrador);

        if ($touchesAdmin && ! ($actor->canAccessApplication() && $actor->checkPermissionTo(PermissionName::AdminsManage->value))) {
            throw BusinessRuleException::because('users.errors.admin_only');
        }
    }

    private function enforceRoleChangeRules(User $actor, User $target, ?UserRole $oldRole): void
    {
        if (! $oldRole?->mustKeepOneActive()) {
            return;
        }

        if ($actor->is($target)) {
            throw BusinessRuleException::because('users.errors.cannot_change_own_role');
        }

        $this->assertNotLastActiveOfRole($target);
    }

    /**
     * El sistema nunca puede quedarse sin un administrador ni sin un jefe de zona activos (mientras el rol
     * exista, alguien debe poder aprobar cuentas). Se bloquea el conjunto completo de activos de ese rol (ordenado
     * por id, para que dos transacciones tomen los bloqueos en el mismo orden) y se cuenta despues del bloqueo,
     * de modo que dos bajas simultaneas no dejen cero.
     */
    private function assertNotLastActiveOfRole(User $target): void
    {
        $role = $target->roleEnum();

        if (! $target->isActive() || $role === null || ! $role->mustKeepOneActive()) {
            return;
        }

        $activeIds = User::query()
            ->activeWithRole($role)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeIds->reject(fn (mixed $id): bool => (int) $id === (int) $target->getKey())->isEmpty()) {
            throw BusinessRuleException::because($role === UserRole::Administrador ? 'users.errors.last_admin' : 'users.errors.last_jefe');
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

    /**
     * Ids unicos y existentes; coordinadores y empleados necesitan al menos uno (el resto puede no tener ninguno).
     *
     * @param  list<int>  $teamIds
     * @return list<int>
     */
    private function normalizeTeamIds(UserRole $role, array $teamIds): array
    {
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));

        if ($role->requiresTeam() && $teamIds === []) {
            throw BusinessRuleException::because('users.errors.team_required');
        }

        if ($teamIds !== [] && Team::query()->whereKey($teamIds)->count() !== count($teamIds)) {
            throw BusinessRuleException::because('users.errors.invalid_team');
        }

        return $teamIds;
    }

    /**
     * El estado no es asignable en masa: se fija aqui de forma explicita.
     * El registro generico del modelo se suprime porque se emite un evento explicito.
     *
     * @param  array{status?: UserStatus}  $attributes
     */
    private function persist(User $target, array $attributes): void
    {
        $target->disableLogging();
        $target->forceFill($attributes)->save();
        $target->enableLogging();
    }

    /**
     * @return array{status: string, role: ?string, team_ids: list<int>}
     */
    private function snapshot(User $user): array
    {
        $user->unsetRelation('roles');

        return [
            'status' => $user->status->value,
            'role' => $user->roleEnum()?->value,
            'team_ids' => $user->teamIds(),
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
