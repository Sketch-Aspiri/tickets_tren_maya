<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Estacion o edificio: nivel superior de la jerarquia Estacion -> Coordinacion -> Equipo.
 */
class Station extends Model
{
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('stations');
    }

    /**
     * @param  Builder<Station>  $query
     * @return Builder<Station>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @return HasMany<Coordination, $this>
     */
    public function coordinations(): HasMany
    {
        return $this->hasMany(Coordination::class);
    }
}
