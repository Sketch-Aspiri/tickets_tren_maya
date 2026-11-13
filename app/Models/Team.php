<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Team extends Model
{
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'coordination_id',
        'name',
        'coordinator_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordination_id' => 'integer',
            'coordinator_id' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['coordination_id', 'name', 'coordinator_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('teams');
    }

    /**
     * Invariante de coordinacion (unica definicion): solo un usuario activo con rol coordinador
     * que sea integrante de este equipo (`team_user`) puede coordinarlo. Un coordinador puede
     * pertenecer, y por tanto coordinar, varios equipos; cada equipo tiene un unico coordinador.
     * Lo usan la regla de validacion ActiveCoordinator y TeamService::releaseInvalidCoordination.
     */
    public function canBeCoordinatedBy(User $user): bool
    {
        return $this->exists
            && $user->isActive()
            && $user->hasSystemRole(UserRole::Coordinador)
            && $user->belongsToTeam($this->getKey());
    }

    /**
     * Equipos que `$actor` puede gestionar: todos (administrador) o los de las coordinaciones que supervisa (jefe
     * de zona). Cualquier otro rol: ninguno.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public function scopeManageableBy(Builder $query, User $actor): Builder
    {
        return match ($actor->roleEnum()) {
            UserRole::Administrador => $query,
            UserRole::JefeZona => $query->whereIn('teams.coordination_id', $actor->coordinationIdsQuery()),
            default => $query->whereIn('teams.id', []),
        };
    }

    /**
     * @return BelongsTo<Coordination, $this>
     */
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(Coordination::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    /**
     * Coordinadores ADICIONALES (decision 96): comparten la gestion del equipo con el principal (`coordinator`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function additionalCoordinators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_coordinator')->withTimestamps()->orderBy('team_coordinator.id');
    }

    /**
     * Todos los coordinadores del equipo, el principal primero y sin repetir (decision 96). Incluye solo cuentas que
     * aun cumplen `canBeCoordinatedBy`, asi que quien se inactivo o cambio de equipo no recibe avisos ni trabajo.
     *
     * @return Collection<int, User>
     */
    public function coordinators(): Collection
    {
        $this->loadMissing(['coordinator', 'additionalCoordinators']);

        return collect([$this->coordinator])
            ->merge($this->additionalCoordinators)
            ->filter()
            ->unique('id')
            ->filter(fn (User $user): bool => $this->canBeCoordinatedBy($user))
            ->values();
    }

    /**
     * Nombres de los coordinadores para mostrar en pantalla (principal primero). Usa las relaciones YA cargadas: el
     * llamador debe cargar `coordinator` y `additionalCoordinators` (evita una consulta por fila).
     */
    public function coordinatorNames(): string
    {
        return collect([$this->coordinator])
            ->merge($this->additionalCoordinators)
            ->filter()
            ->unique('id')
            ->pluck('name')
            ->implode(', ');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
