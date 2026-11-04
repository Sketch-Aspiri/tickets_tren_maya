<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Category extends Model
{
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'coordination_id',
        'name',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordination_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['coordination_id', 'name', 'active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('categories');
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Categorias que `$actor` puede USAR: las globales (sin coordinacion) y las de las coordinaciones a las que
     * pertenece o que supervisa; el administrador, todas.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->seesAllTeams()) {
            return $query;
        }

        return $query->where(fn (Builder $scope) => $scope
            ->whereNull('categories.coordination_id')
            ->orWhereIn('categories.coordination_id', $actor->coordinationIdsQuery()));
    }

    /**
     * Categorias que `$actor` puede ADMINISTRAR: todas (administrador) o solo las de sus coordinaciones (jefe de
     * zona; las globales son del administrador).
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeManageableBy(Builder $query, User $actor): Builder
    {
        return match ($actor->roleEnum()) {
            UserRole::Administrador => $query,
            UserRole::JefeZona => $query->whereIn('categories.coordination_id', $actor->coordinationIdsQuery()),
            default => $query->whereIn('categories.id', []),
        };
    }

    /**
     * Coordinacion duena de la categoria; `null` = categoria global.
     *
     * @return BelongsTo<Coordination, $this>
     */
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(Coordination::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
