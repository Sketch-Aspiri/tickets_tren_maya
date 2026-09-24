<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Asignación polimórfica (ticket hoy, actividad en el Sprint 3). Se crea únicamente desde
 * AssignmentService, que registra el evento de bitácora sobre el ticket.
 */
class Assignment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'role',
        'assigned_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AssignmentRole::class,
            'user_id' => 'integer',
            'assigned_by' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
