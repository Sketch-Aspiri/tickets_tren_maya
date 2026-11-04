<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
