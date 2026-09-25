<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Subtask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Subtareas de una actividad: agregar, editar, eliminar y marcar hecha/no hecha. Todo se hace bajo el lock
 * de la actividad y con la autorización revalidada; cada cambio queda en la bitácora de la actividad.
 *
 * Reglas:
 * - una actividad final (Completado/Cancelado) no admite ningún cambio en sus subtareas: primero se reabre;
 * - una plantilla de recurrencia sí puede tener subtareas (se copian a cada instancia, reiniciadas) pero no se
 *   marcan en ella (solo se trabajan las instancias);
 * - el responsable de una subtarea debe ser un usuario activo asignado a la actividad o de su equipo;
 * - hay un tope de subtareas por actividad (`tickets.max_subtasks`).
 */
final class SubtaskService
{
    private const LOG = 'activities';

    public function __construct(private readonly AuditLogger $audit) {}

    public function add(User $actor, Activity $activity, string $title, ?int $assignedTo): Subtask
    {
        return DB::transaction(function () use ($actor, $activity, $title, $assignedTo): Subtask {
            $locked = $this->lockOpen($actor, $activity, 'create', [Subtask::class, $activity]);

            if ($locked->subtasks()->count() >= (int) config('tickets.max_subtasks')) {
                throw BusinessRuleException::because('activities.errors.too_many_subtasks', ['max' => (int) config('tickets.max_subtasks')]);
            }

            $this->assertAssignee($locked, $assignedTo);

            $subtask = new Subtask(['title' => trim($title)]);
            $subtask->forceFill(['activity_id' => $locked->getKey(), 'assigned_to' => $assignedTo, 'done' => false, 'done_at' => null])->save();

            $this->record($locked, 'subtask_added', $actor, $subtask);

            return $subtask;
        });
    }

    public function update(User $actor, Subtask $subtask, string $title, ?int $assignedTo): Subtask
    {
        return DB::transaction(function () use ($actor, $subtask, $title, $assignedTo): Subtask {
            $locked = $this->lockOpen($actor, $subtask->activity, 'update', $subtask);
            $fresh = Subtask::query()->lockForUpdate()->findOrFail($subtask->getKey());

            $this->assertAssignee($locked, $assignedTo);

            $before = ['title' => $fresh->title, 'assigned_to' => $fresh->assigned_to];
            $fresh->title = trim($title);
            $fresh->assigned_to = $assignedTo;
            $fresh->save();

            $this->record($locked, 'subtask_updated', $actor, $fresh, $before, ['title' => $fresh->title, 'assigned_to' => $fresh->assigned_to]);

            return $fresh;
        });
    }

    public function remove(User $actor, Subtask $subtask): void
    {
        DB::transaction(function () use ($actor, $subtask): void {
            $locked = $this->lockOpen($actor, $subtask->activity, 'delete', $subtask);

            $subtask->delete();

            $this->record($locked, 'subtask_removed', $actor, $subtask);
        });
    }

    /**
     * Marca o desmarca (idempotente: sin cambio real no se escribe ni se registra nada).
     */
    public function setDone(User $actor, Subtask $subtask, bool $done): Subtask
    {
        return DB::transaction(function () use ($actor, $subtask, $done): Subtask {
            $locked = $this->lockOpen($actor, $subtask->activity, 'markDone', $subtask);
            $fresh = Subtask::query()->lockForUpdate()->findOrFail($subtask->getKey());

            if ($locked->isTemplate()) {
                throw BusinessRuleException::because('activities.errors.template_subtask_done');
            }

            if ($fresh->done === $done) {
                return $fresh;
            }

            $fresh->done = $done;
            $fresh->done_at = $done ? now() : null;
            $fresh->save();

            $this->record($locked, $done ? 'subtask_done' : 'subtask_undone', $actor, $fresh);

            return $fresh;
        });
    }

    /**
     * Bloquea la actividad, revalida el permiso sobre la fila fresca y rechaza actividades finales.
     *
     * @param  Subtask|array{0: class-string<Subtask>, 1: Activity}  $arguments  Argumentos de la Policy.
     */
    private function lockOpen(User $actor, Activity $activity, string $ability, Subtask|array $arguments): Activity
    {
        $locked = Activity::query()->lockForUpdate()->findOrFail($activity->getKey());

        Gate::forUser($actor)->authorize($ability, is_array($arguments) ? [Subtask::class, $locked] : $arguments);

        if ($locked->status->isFinal()) {
            throw BusinessRuleException::because('activities.errors.closed_subtasks');
        }

        return $locked;
    }

    /**
     * Sin responsable, o un usuario activo con rol que esté asignado a la actividad o sea de su equipo.
     */
    private function assertAssignee(Activity $activity, ?int $assignedTo): void
    {
        if ($assignedTo === null) {
            return;
        }

        $candidate = User::query()->with('roles:id,name')->find($assignedTo);

        $isEligible = $candidate !== null
            && $candidate->canAccessApplication()
            && ($candidate->belongsToTeam($activity->team_id)
                || $activity->assignments()->where('user_id', $candidate->getKey())->exists());

        if (! $isEligible) {
            throw BusinessRuleException::because('activities.errors.invalid_subtask_assignee');
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function record(Activity $activity, string $event, User $actor, Subtask $subtask, array $old = [], array $new = []): void
    {
        $this->audit->record(self::LOG, $event, $activity, $actor, $old, $new, [
            'folio' => $activity->folio,
            'subtask_id' => $subtask->getKey(),
            'title' => $subtask->title,
        ]);
    }
}
