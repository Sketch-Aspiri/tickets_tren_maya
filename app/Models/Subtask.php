<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subtarea de una actividad: título, responsable opcional y hecha/no hecha (`done_at`). Se crea, edita
 * y marca únicamente desde SubtaskService, que audita cada cambio sobre la actividad dueña.
 */
class Subtask extends Model
{
    use HasFactory;

    /**
     * `activity_id`, `assigned_to`, `done` y `done_at` los fija SubtaskService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_id' => 'integer',
            'assigned_to' => 'integer',
            'done' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
