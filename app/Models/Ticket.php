<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Ticket extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Solo lo que el usuario puede escribir en el formulario. `folio`, `status`, `team_id`,
     * `created_by`, `source` y `completed_at` se fijan únicamente desde TicketService (forceFill).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'priority',
        'category_id',
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
            'source' => TicketSource::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'category_id' => 'integer',
            'team_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * Los cambios de estado y de asignación se registran con eventos explícitos (AuditLogger).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['folio', 'title', 'priority', 'status', 'category_id', 'team_id', 'due_date', 'source'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tickets');
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
     * ALCANCE por rol: única definición de "qué tickets puede ver este usuario" (listados, Mis
     * pendientes, TicketPolicy::view y cualquier consulta futura).
     *
     * - Jefe de zona: todos.
     * - Coordinador: los de su equipo (`users.team_id` es la fuente de verdad, decisión 17) y los que
     *   se le asignaron explícitamente (si no, un ticket asignado por el jefe fuera de su equipo sería
     *   trabajo suyo que no puede abrir).
     * - Empleado: los que creó, los asignados a él y la bolsa (sin asignar) de su equipo.
     * - Sin acceso a la aplicación (pendiente/inactivo/sin rol): ninguno.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $none = fn (): Builder => $query->whereIn('tickets.id', []);

        if (! $user->canAccessApplication()) {
            return $none();
        }

        return match ($user->roleEnum()) {
            UserRole::JefeZona => $query,
            UserRole::Coordinador => $query->where(fn (Builder $scope) => $this->applyCoordinatorScope($scope, $user)),
            UserRole::Empleado => $query->where(fn (Builder $scope) => $this->applyEmployeeScope($scope, $user)),
            default => $none(),
        };
    }

    /**
     * @param  Builder<Ticket>  $scope
     */
    private function applyCoordinatorScope(Builder $scope, User $user): void
    {
        $scope->whereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $user->getKey()));

        if ($user->team_id !== null) {
            $scope->orWhere('tickets.team_id', $user->team_id);
        }
    }

    /**
     * @param  Builder<Ticket>  $scope
     */
    private function applyEmployeeScope(Builder $scope, User $user): void
    {
        $scope->where('tickets.created_by', $user->getKey())
            ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $user->getKey()));

        if ($user->team_id !== null) {
            $scope->orWhere(fn (Builder $bag) => $bag
                ->where('tickets.team_id', $user->team_id)
                ->whereDoesntHave('assignments'));
        }
    }

    /**
     * Vencido = fecha límite anterior a hoy (hora de negocio) y estado no final. Nunca es un estado.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('tickets.due_date')
            ->where('tickets.due_date', '<', LocalTime::today())
            ->whereNotIn('tickets.status', TicketStatus::finalValues());
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('tickets.status', TicketStatus::finalValues());
    }

    /**
     * Sin ningún asignado: la "bolsa" del equipo.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereDoesntHave('assignments');
    }

    // --- Consultas de instancia --------------------------------------------

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->toDateString() < LocalTime::today()
            && ! $this->status->isFinal();
    }

    /**
     * "Lo suyo" para un empleado: lo creó o está asignado a él.
     */
    public function isInvolving(User $user): bool
    {
        return (int) $this->created_by === (int) $user->getKey()
            || $this->assignments()->where('user_id', $user->getKey())->exists();
    }
}
