<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buzon IMAP de una coordinacion. La contrasena va cifrada (`encrypted`) y oculta: nunca se serializa, se
 * muestra ni se registra en la bitacora.
 */
final class CoordinationMailbox extends Model
{
    public const ENCRYPTIONS = ['ssl', 'tls', 'starttls', 'none'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'coordination_id',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'folder',
        'active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordination_id' => 'integer',
            'port' => 'integer',
            'password' => 'encrypted',
            'active' => 'boolean',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<CoordinationMailbox>  $query
     * @return Builder<CoordinationMailbox>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @return BelongsTo<Coordination, $this>
     */
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(Coordination::class);
    }
}
