<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Concerns\HasWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tarea planificada, asignada, con subtareas y posible recurrencia (sección 1 de CLAUDE.md).
 *
 * Tres formas de fila:
 * - normal: sin `recurrence_rule` ni `parent_activity_id`;
 * - PLANTILLA (madre): tiene `recurrence_rule`. Define la serie y NO se trabaja como una actividad normal
 *   (solo se cancela/reabre/edita); sus instancias son las que se ejecutan;
 * - INSTANCIA: tiene `parent_activity_id` y `occurrence_date`. Se gestiona de forma independiente.
 */
class Activity extends Model
{
    use HasFactory, HasWorkflow, LogsActivity, SoftDeletes;

    /**
     * Solo lo que el usuario escribe en el formulario. `folio`, `status`, `team_id`, `created_by`,
     * `recurrence_rule`, `parent_activity_id`, `occurrence_date` y `completed_at` se fijan únicamente
     * desde ActivityService / RecurrenceService (forceFill).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'priority',
        'category_id',
        'start_date',
        'due_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => TicketStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'occurrence_date' => 'date',
            'recurrence_rule' => 'array',
            'completed_at' => 'datetime',
            'category_id' => 'integer',
            'team_id' => 'integer',
            'created_by' => 'integer',
            'parent_activity_id' => 'integer',
        ];
    }

    /**
     * Los cambios de estado, asignación y subtareas se registran con eventos explícitos (AuditLogger).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['folio', 'title', 'priority', 'status', 'category_id', 'team_id', 'start_date', 'due_date', 'recurrence_rule', 'parent_activity_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('activities');
    }

    // --- Relaciones ---------------------------------------------------------

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Plantilla de la que salió esta instancia.
     *
     * @return BelongsTo<Activity, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_activity_id');
    }

    /**
     * Instancias generadas por esta plantilla.
     *
     * @return HasMany<Activity, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(self::class, 'parent_activity_id');
    }

    /**
     * @return HasMany<Subtask, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class);
    }

    /**
     * @return MorphMany<Assignment, $this>
     */
    public function assignments(): MorphMany
    {
        return $this->morphMany(Assignment::class, 'assignable');
    }

    /**
     * @return MorphMany<StatusHistory, $this>
     */
    public function statusHistories(): MorphMany
    {
        return $this->morphMany(StatusHistory::class, 'historable');
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // --- Consultas reutilizables -------------------------------------------

    /**
     * ALCANCE por rol: única definición de "qué actividades puede ver este usuario" (listados, Mis
     * pendientes y ActivityPolicy::view).
     *
     * - Jefe de zona: todas.
     * - Coordinador: las de su equipo (`users.team_id`, decisión 17) y las que se le asignaron
     *   explícitamente (igual que en tickets).
     * - Empleado: solo las asignadas a él (responsable o colaborador) o con una subtarea asignada a él
     *   (una subtarea puede ir a alguien del equipo que no está asignado a la actividad, y debe poder
     *   abrirla). NUNCA ve plantillas de recurrencia: trabaja con sus instancias.
     * - Sin acceso a la aplicación (pendiente/inactivo/sin rol): ninguna.
     * Las actividades no tienen "bolsa": no hay acceso por pertenecer al equipo (salvo el coordinador).
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->canAccessApplication()) {
            return $query->whereIn('activities.id', []);
        }

        return match ($user->roleEnum()) {
            UserRole::JefeZona => $query,
            UserRole::Coordinador => $query->where(fn (Builder $scope) => $this->applyCoordinatorScope($scope, $user)),
            UserRole::Empleado => $query
                ->whereNull('activities.recurrence_rule')
                ->where(fn (Builder $scope) => $scope->assignedTo($user)->orWhere(fn (Builder $subtask) => $subtask->hasSubtaskFor($user))),
            default => $query->whereIn('activities.id', []),
        };
    }

    /**
     * @param  Builder<Activity>  $scope
     */
    private function applyCoordinatorScope(Builder $scope, User $user): void
    {
        $scope->assignedTo($user);

        if ($user->team_id !== null) {
            $scope->orWhere('activities.team_id', $user->team_id);
        }
    }

    /**
     * Con una asignación (responsable o colaborador) para el usuario.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $user->getKey()));
    }

    /**
     * Con al menos una subtarea asignada al usuario (`$onlyPending`: solo las que siguen sin hacer).
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeHasSubtaskFor(Builder $query, User $user, bool $onlyPending = false): Builder
    {
        return $query->whereHas('subtasks', fn (Builder $subtask) => $subtask
            ->where('assigned_to', $user->getKey())
            ->when($onlyPending, fn (Builder $pending) => $pending->where('done', false)));
    }

    /**
     * Plantillas de recurrencia (madres de una serie).
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNotNull('activities.recurrence_rule');
    }

    /**
     * Todo menos las plantillas: actividades normales e instancias (lo que realmente se trabaja).
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeWithoutTemplates(Builder $query): Builder
    {
        return $query->whereNull('activities.recurrence_rule');
    }

    /**
     * Agrega `subtasks_count` y `subtasks_done_count` en la MISMA consulta (subconsultas), para calcular
     * el avance de todo un listado sin cargar subtareas ni hacer una consulta por fila.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeWithProgress(Builder $query): Builder
    {
        return $query->withCount([
            'subtasks',
            'subtasks as subtasks_done_count' => fn (Builder $subtask) => $subtask->where('done', true),
        ]);
    }

    // --- Consultas de instancia --------------------------------------------

    public function isTemplate(): bool
    {
        return $this->recurrence_rule !== null;
    }

    public function isInstance(): bool
    {
        return $this->parent_activity_id !== null;
    }

    /**
     * Avance 0-100: subtareas hechas / total, redondeado hacia ABAJO (así nunca marca 100 % con algo
     * pendiente); 0 % si no hay subtareas. Usa los conteos de `withProgress()` si ya vienen cargados
     * y, si no, hace UNA consulta agregada.
     */
    public function progressPercent(): int
    {
        return self::percentage($this->subtasksDoneCount(), $this->subtasksTotalCount());
    }

    public function subtasksTotalCount(): int
    {
        return (int) ($this->getAttribute('subtasks_count') ?? $this->subtasks()->count());
    }

    public function subtasksDoneCount(): int
    {
        return (int) ($this->getAttribute('subtasks_done_count') ?? $this->subtasks()->where('done', true)->count());
    }

    public static function percentage(int $done, int $total): int
    {
        return $total === 0 ? 0 : intdiv($done * 100, $total);
    }

    /**
     * "Lo suyo" para un empleado: está asignado a la actividad (responsable o colaborador).
     */
    public function isInvolving(User $user): bool
    {
        return $this->assignments()->where('user_id', $user->getKey())->exists();
    }
}
