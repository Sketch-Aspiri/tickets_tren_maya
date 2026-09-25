<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
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
        'name',
        'coordinator_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordinator_id' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'coordinator_id'])
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
