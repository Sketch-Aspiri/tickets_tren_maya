<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, LogsActivity, Notifiable;

    /**
     * Solo lo que el propio usuario puede enviar al registrarse. `status`, los equipos
     * (`team_user`) y los campos de 2FA se asignan exclusivamente desde Services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'avatar_path',
        'two_factor_recovery_codes',
        'two_factor_last_timestamp',
    ];

    /**
     * @return array<string, string>
     */
    /**
     * Si cambia el correo se rompe el vinculo con el Dashboard (el inicio de sesion unico se basa en el
     * correo, decision 88): hay que volver a vincular con contrasena.
     */
    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('email')) {
                $user->sso_linked_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_secret' => 'encrypted',
            'sso_linked_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestamp' => 'integer',
        ];
    }

    /**
     * Campos seguros para la bitacora: nunca contrasenas, tokens ni secretos 2FA.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('users');
    }

    /**
     * Coordinacion que la persona eligio al registrarse.
     *
     * @return BelongsTo<Coordination, $this>
     */
    public function requestedCoordination(): BelongsTo
    {
        return $this->belongsTo(Coordination::class, 'requested_coordination_id');
    }

    /**
     * Equipos a los que pertenece (cualquier rol puede estar en varios a la vez).
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    /**
     * Coordinaciones que supervisa (solo jefes de zona).
     *
     * @return BelongsToMany<Coordination, $this>
     */
    public function coordinations(): BelongsToMany
    {
        return $this->belongsToMany(Coordination::class)->withTimestamps();
    }

    /**
     * Coordinacion bajo la que se agrupa al usuario en los listados: la de sus equipos, si no la que supervisa
     * (jefe de zona) y si no la que solicito al registrarse; con varias gana la de menor nombre. Usa las
     * relaciones YA cargadas (`teams.coordination`, `coordinations`, `requestedCoordination`): sin consultas.
     * Debe coincidir con el orden SQL de `UserService::paginate`.
     */
    public function primaryCoordination(): ?Coordination
    {
        $candidates = $this->teams->pluck('coordination')->filter();

        if ($candidates->isEmpty()) {
            $candidates = $this->coordinations;
        }

        if ($candidates->isEmpty()) {
            return $this->requestedCoordination;
        }

        return $candidates->sortBy('name')->first();
    }

    /**
     * Ids de sus equipos, siempre leidos de la base (nunca de una relacion cargada que pudo quedar vieja).
     *
     * @return list<int>
     */
    public function teamIds(): array
    {
        return $this->teams()->pluck('teams.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
    }

    /**
     * Subconsulta con los ids de sus equipos, para usarla dentro de otra consulta sin una lectura previa.
     */
    public function teamIdsQuery(): QueryBuilder
    {
        return DB::table('team_user')->select('team_id')->where('user_id', $this->getKey());
    }

    public function belongsToTeam(int|string|null $teamId): bool
    {
        return $teamId !== null && $this->teams()->whereKey((int) $teamId)->exists();
    }

    /**
     * Usuarios que pertenecen a al menos uno de los equipos dados (sin ninguno: nadie).
     *
     * @param  Builder<User>  $query
     * @param  list<int>  $teamIds
     * @return Builder<User>
     */
    public function scopeMemberOfAny(Builder $query, array $teamIds): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn('id'),
            DB::table('team_user')->select('user_id')->whereIn('team_id', $teamIds),
        );
    }

    /**
     * Solo el administrador ve todos los equipos de todas las coordinaciones; para el resto el alcance es
     * `scopedTeamIdsQuery()`.
     */
    public function seesAllTeams(): bool
    {
        return $this->roleEnum()?->hasGlobalScope() ?? false;
    }

    /**
     * Ids de las coordinaciones que supervisa (jefe de zona, via `coordination_user`) o a las que pertenece
     * (coordinador y empleado, via la coordinacion de sus equipos). Subconsulta, sin lectura previa.
     */
    public function coordinationIdsQuery(): QueryBuilder
    {
        if ($this->roleEnum() === UserRole::JefeZona) {
            return DB::table('coordination_user')->select('coordination_id')->where('user_id', $this->getKey());
        }

        return DB::table('teams')->select('coordination_id')->whereIn('id', $this->teamIdsQuery());
    }

    /**
     * Memoria de `coordinationIds()` (una instancia de usuario vive lo que dura una peticion).
     *
     * @var list<int>|null
     */
    private ?array $coordinationIdsMemo = null;

    /**
     * Ids de las coordinaciones del usuario como lista, memorizados en la instancia: las Policies de listados la
     * consultan una vez por fila y no deben generar una consulta cada vez. Usa `forgetCoordinationIds()` si el
     * conjunto cambia durante la misma peticion.
     *
     * @return list<int>
     */
    public function coordinationIds(): array
    {
        return $this->coordinationIdsMemo ??= $this->coordinationIdsQuery()
            ->pluck('coordination_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function forgetCoordinationIds(): void
    {
        $this->coordinationIdsMemo = null;
    }

    /**
     * Coordinaciones (con su estacion) donde trabaja: las de sus equipos mas las que supervisa si es jefe de
     * zona. Para listados conviene precargar `teams.coordination.station` y `coordinations.station`.
     *
     * @return \Illuminate\Support\Collection<int, Coordination>
     */
    public function workplaces(): \Illuminate\Support\Collection
    {
        return $this->teams->pluck('coordination')
            ->merge($this->coordinations)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Texto corto para mostrar a que coordinacion(es) pertenece o cual(es) supervisa: el nombre si es una, el
     * numero si son varias, vacio para el administrador (global) o quien aun no tiene ninguna.
     */
    public function coordinationLabel(): string
    {
        if ($this->seesAllTeams()) {
            return '';
        }

        $ids = $this->coordinationIds();

        return match (count($ids)) {
            0 => '',
            1 => (string) Coordination::query()->whereKey($ids[0])->value('name'),
            default => trans_choice('common.nav.coordination_count', count($ids), ['count' => count($ids)]),
        };
    }

    /**
     * UNICA definicion de "equipos que gestiona/ve como unidad": `null` = todos (administrador); jefe de zona =
     * equipos de sus coordinaciones; coordinador y empleado = sus equipos. Subconsulta, sin lectura previa.
     */
    public function scopedTeamIdsQuery(): ?QueryBuilder
    {
        return match ($this->roleEnum()) {
            UserRole::Administrador => null,
            UserRole::JefeZona => DB::table('teams')->select('id')->whereIn('coordination_id', $this->coordinationIdsQuery()),
            default => $this->teamIdsQuery(),
        };
    }

    /**
     * Lo mismo que `scopedTeamIdsQuery()` pero como lista (`null` = todos), leida de la base.
     *
     * @return list<int>|null
     */
    public function scopedTeamIds(): ?array
    {
        $query = $this->scopedTeamIdsQuery();

        if ($query === null) {
            return null;
        }

        $column = $query->columns[0] ?? 'team_id';

        return $query->pluck($column)->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
    }

    /**
     * Equipos entre los que debe ELEGIR al crear o filtrar: todos (alcance global) o los suyos si son mas de
     * uno. Con un solo equipo no hay nada que elegir (se usa ese), y sin ninguno tampoco.
     *
     * @return Collection<int, Team>
     */
    public function selectableTeams(): Collection
    {
        // `coordination.station` viaja cargada: los selectores muestran los equipos por edificio y coordinacion.
        $withOrg = fn () => Team::query()->with('coordination:id,name,station_id', 'coordination.station:id,name')->orderBy('name');

        if ($this->seesAllTeams()) {
            return $withOrg()->get(['id', 'name', 'coordination_id']);
        }

        if ($this->roleEnum() === UserRole::JefeZona) {
            return $withOrg()->whereIn('coordination_id', $this->coordinationIdsQuery())->get(['id', 'name', 'coordination_id']);
        }

        $teams = $this->teams()
            ->with('coordination:id,name,station_id', 'coordination.station:id,name')
            ->orderBy('teams.name')
            ->get(['teams.id', 'teams.name', 'teams.coordination_id']);

        return $teams->count() > 1 ? $teams : new Collection;
    }

    /**
     * Equipo para un ticket/actividad nuevo: el unico equipo del usuario; si tiene varios (o alcance global) el
     * indicado, que debe ser uno de los suyos (el alcance global acepta cualquiera). `null` si no se puede decidir.
     */
    public function workTeamIdFor(?int $requested): ?int
    {
        if ($this->seesAllTeams()) {
            return $requested;
        }

        $own = $this->workingTeamIds();

        if (count($own) === 1) {
            return $own[0];
        }

        return $requested !== null && in_array($requested, $own, true) ? $requested : null;
    }

    /**
     * Equipos entre los que trabaja un usuario sin alcance global: los de las coordinaciones que supervisa (jefe
     * de zona) o los propios (coordinador y empleado).
     *
     * @return list<int>
     */
    public function workingTeamIds(): array
    {
        return $this->roleEnum() === UserRole::JefeZona ? ($this->scopedTeamIds() ?? []) : $this->teamIds();
    }

    /**
     * Debe elegir el equipo en el formulario (mas de uno o alcance global).
     */
    public function mustChooseTeam(): bool
    {
        return $this->seesAllTeams() || count($this->workingTeamIds()) > 1;
    }

    /**
     * Equipo para un TICKET nuevo: a diferencia de `workTeamIdFor` (actividades), cualquier usuario puede
     * enviar un ticket a CUALQUIER equipo (p. ej. un empleado manda un ticket al equipo de TI), no solo a
     * los suyos; `$requested` ya viene validado como un equipo existente (Form Request). Sin equipo pedido,
     * se usa el único propio; con varios equipos o alcance global no hay equipo por defecto y debe elegirlo.
     */
    public function ticketTeamIdFor(?int $requested): ?int
    {
        if ($requested !== null) {
            return $requested;
        }

        $own = $this->workingTeamIds();

        return count($own) === 1 ? $own[0] : null;
    }

    /**
     * Usuarios que `$actor` puede ver y gestionar: el administrador a todos; un jefe de zona a si mismo, a los
     * integrantes de los equipos de sus coordinaciones y a las cuentas que solicitaron una de sus coordinaciones.
     * Nunca a otros jefes ni a administradores (los gestiona el administrador). Coordinador y empleado: solo a si.
     * Las cuentas sin equipo ni coordinacion solicitada (aun sin ubicar) las puede ubicar cualquier jefe.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeManageableBy(Builder $query, User $actor): Builder
    {
        if ($actor->seesAllTeams()) {
            return $query;
        }

        if ($actor->roleEnum() !== UserRole::JefeZona) {
            return $query->whereKey($actor->getKey());
        }

        return $query->where(function (Builder $scope) use ($actor): void {
            $scope->whereKey($actor->getKey())
                ->orWhere(function (Builder $others) use ($actor): void {
                    $others->whereDoesntHave('roles', fn (Builder $role) => $role->whereIn('name', [UserRole::Administrador->value, UserRole::JefeZona->value]))
                        ->where(function (Builder $reach) use ($actor): void {
                            $reach->whereIn('users.id', DB::table('team_user')->select('user_id')->whereIn('team_id', $actor->scopedTeamIdsQuery()))
                                ->orWhere(fn (Builder $requested) => $requested
                                    ->where('users.status', '!=', UserStatus::Active->value)
                                    ->whereIn('users.requested_coordination_id', $actor->coordinationIdsQuery()))
                                // Sin equipo ni coordinacion solicitada: cuenta aun sin ubicar; cualquier jefe puede darle lugar.
                                ->orWhere(fn (Builder $orphan) => $orphan
                                    ->whereNull('users.requested_coordination_id')
                                    ->where('users.status', '!=', UserStatus::Active->value)
                                    ->whereNotIn('users.id', DB::table('team_user')->select('user_id')));
                        });
                });
        });
    }

    /**
     * Usuarios activos con el rol dado (unica definicion de "activo con rol" para consultas).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActiveWithRole(Builder $query, UserRole $role): Builder
    {
        return $query
            ->role($role->value)
            ->where('status', UserStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::Pending;
    }

    public function isInactive(): bool
    {
        return $this->status === UserStatus::Inactive;
    }

    /**
     * Rol principal del usuario (un usuario tiene exactamente un rol del sistema).
     */
    public function roleEnum(): ?UserRole
    {
        // Una sola consulta si aún no se cargaron (p. ej. en consola); en los listados ya vienen con `with('roles')`.
        $name = $this->loadMissing('roles')->roles->first()?->name;

        return $name === null ? null : UserRole::tryFrom($name);
    }

    public function hasSystemRole(UserRole $role): bool
    {
        return $this->roleEnum() === $role;
    }

    /**
     * Un usuario solo puede usar la aplicacion si esta activo y tiene rol.
     */
    public function canAccessApplication(): bool
    {
        return $this->isActive() && $this->roleEnum() !== null;
    }

    /**
     * URL de la foto de perfil (null sin foto). `v` cambia con cada foto nueva para que el navegador no muestre
     * la anterior desde su cache; no revela la ruta interna.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        return route('avatars.show', ['user' => $this->getKey(), 'v' => substr(sha1((string) $this->avatar_path), 0, 10)]);
    }

    public function requiresTwoFactor(): bool
    {
        return $this->roleEnum()?->requiresTwoFactor() ?? false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
