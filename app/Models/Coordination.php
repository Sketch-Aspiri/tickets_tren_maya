<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Coordinacion: agrupa equipos dentro de una estacion y la supervisan uno o varios jefes de zona.
 */
class Coordination extends Model
{
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'station_id',
        'name',
        'code',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'station_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['station_id', 'name', 'code', 'active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('coordinations');
    }

    /**
     * @param  Builder<Coordination>  $query
     * @return Builder<Coordination>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasOne<CoordinationMailbox, $this>
     */
    public function mailbox(): HasOne
    {
        return $this->hasOne(CoordinationMailbox::class);
    }

    /**
     * Quienes reciben los avisos de la coordinacion (cuentas por aprobar, correos nuevos): sus jefes de zona
     * activos; si no tiene ninguno, los administradores activos.
     *
     * @return Collection<int, User>
     */
    public function approvers(): Collection
    {
        $bosses = $this->bosses()->where('users.status', UserStatus::Active->value)->get();

        if ($bosses->isNotEmpty()) {
            return $bosses;
        }

        return User::query()->activeWithRole(UserRole::Administrador)->get();
    }

    /**
     * Jefes de zona que supervisan esta coordinacion.
     *
     * @return BelongsToMany<User, $this>
     */
    public function bosses(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * "Estacion › Coordinacion", para selectores y listados.
     */
    public function fullName(): string
    {
        return ($this->station?->name ?? '').' › '.$this->name;
    }
}
