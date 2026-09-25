<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

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
        'two_factor_recovery_codes',
        'two_factor_last_timestamp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_secret' => 'encrypted',
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
     * Equipos a los que pertenece (cualquier rol puede estar en varios a la vez).
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
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
     * Administrador y jefe de zona ven todos los equipos; para el resto el alcance son sus equipos.
     */
    public function seesAllTeams(): bool
    {
        return $this->roleEnum()?->hasGlobalScope() ?? false;
    }

    /**
     * Equipos entre los que debe ELEGIR al crear o filtrar: todos (alcance global) o los suyos si son mas de
     * uno. Con un solo equipo no hay nada que elegir (se usa ese), y sin ninguno tampoco.
     *
     * @return Collection<int, Team>
     */
    public function selectableTeams(): Collection
    {
        if ($this->seesAllTeams()) {
            return Team::query()->orderBy('name')->get(['id', 'name']);
        }

        $teams = $this->teams()->orderBy('teams.name')->get(['teams.id', 'teams.name']);

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

        $own = $this->teamIds();

        if (count($own) === 1) {
            return $own[0];
        }

        return $requested !== null && in_array($requested, $own, true) ? $requested : null;
    }

    /**
     * Debe elegir el equipo en el formulario (mas de uno o alcance global).
     */
    public function mustChooseTeam(): bool
    {
        return $this->seesAllTeams() || count($this->teamIds()) > 1;
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
        $name = $this->roles->first()?->name;

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

    public function requiresTwoFactor(): bool
    {
        return $this->roleEnum()?->requiresTwoFactor() ?? false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
