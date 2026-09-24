<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Subtask;
use App\Support\LocalTime;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Genera las INSTANCIAS de las actividades recurrentes (sección 6 de CLAUDE.md).
 *
 * Modelo: la actividad con `recurrence_rule` es la PLANTILLA (madre); cada ocurrencia es otra actividad con
 * `parent_activity_id` y `occurrence_date`, con su propio folio, estado, historial, asignaciones (copiadas de
 * la plantilla) y subtareas (copiadas y reiniciadas como no hechas). Se gestionan de forma independiente.
 *
 * - Ventana: de HOY (hora de negocio, America/Cancun) a HOY + `tickets.recurrence.horizon_days`, ambos
 *   inclusive. Las ocurrencias pasadas nunca se generan retroactivamente.
 * - Idempotente: el índice único (parent_activity_id, occurrence_date) y la comprobación bajo lock de la
 *   plantilla impiden duplicar una ocurrencia aunque el comando corra dos veces o en paralelo; una instancia
 *   eliminada tampoco se regenera.
 * - Una plantilla cancelada o eliminada no genera; reabrirla reanuda la serie.
 * - Los asignados que dejaron de estar activos se omiten y queda constancia en la bitácora.
 */
final class RecurrenceService
{
    public function __construct(
        private readonly FolioGenerator $folios,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Recorre todas las plantillas activas. Un fallo en una no detiene a las demás (se reporta y se cuenta).
     *
     * @return array{templates: int, created: int, failed: int}
     */
    public function generateAll(): array
    {
        $result = ['templates' => 0, 'created' => 0, 'failed' => 0];

        Activity::query()
            ->templates()
            ->where('status', TicketStatus::Pending->value)
            ->orderBy('id')
            ->chunkById(100, function ($templates) use (&$result): void {
                foreach ($templates as $template) {
                    $result['templates']++;

                    try {
                        $result['created'] += $this->generateFor($template);
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }
            });

        return $result;
    }

    /**
     * Genera las instancias que falten para una plantilla dentro de la ventana. Devuelve cuántas creó.
     */
    public function generateFor(Activity $template): int
    {
        if (! $template->isTemplate() || $template->status !== TicketStatus::Pending || $template->start_date === null) {
            return 0;
        }

        $rule = RecurrenceRule::fromArray((array) $template->recurrence_rule);
        $today = LocalTime::now();
        $dates = $rule->occurrencesBetween(
            $template->start_date->toDateString(),
            $today->toDateString(),
            $today->addDays(max(0, (int) config('tickets.recurrence.horizon_days')))->toDateString(),
        );

        $created = 0;

        foreach ($dates as $date) {
            $created += $this->createInstance($template, $date) ? 1 : 0;
        }

        return $created;
    }

    /**
     * Crea la instancia de `$date` si aún no existe. Bajo lock de la plantilla se vuelve a comprobar que siga
     * activa y que la ocurrencia no exista (incluso eliminada); si otro proceso ganó la carrera, el índice
     * único lo detiene y simplemente no se crea.
     */
    private function createInstance(Activity $template, string $date): bool
    {
        try {
            return DB::transaction(function () use ($template, $date): bool {
                $fresh = Activity::query()->lockForUpdate()->find($template->getKey());

                if ($fresh === null || $fresh->status !== TicketStatus::Pending || ! $fresh->isTemplate()) {
                    return false;
                }

                if ($fresh->instances()->withTrashed()->whereDate('occurrence_date', $date)->exists()) {
                    return false;
                }

                $instance = $this->newInstance($fresh, $date);
                $this->copyAssignments($fresh, $instance);
                $this->copySubtasks($fresh, $instance);

                $this->audit->record('activities', 'instance_generated', $fresh, null, [], [], [
                    'folio' => $fresh->folio,
                    'instance_id' => $instance->getKey(),
                    'instance_folio' => $instance->folio,
                    'occurrence_date' => $date,
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function newInstance(Activity $template, string $date): Activity
    {
        $instance = new Activity([
            'title' => $template->title,
            'description' => $template->description,
            'priority' => $template->priority,
            'category_id' => $template->category_id,
        ]);

        $instance->forceFill([
            'folio' => $this->folios->next((string) config('tickets.folio_prefixes.activity')),
            'status' => TicketStatus::Pending,
            'team_id' => $template->team_id,
            'created_by' => $template->created_by,
            'start_date' => $date,
            'due_date' => $this->dueDateFor($template, $date),
            'recurrence_rule' => null,
            'parent_activity_id' => $template->getKey(),
            'occurrence_date' => $date,
            'completed_at' => null,
        ])->save();

        $instance->statusHistories()->create([
            'from_status' => null,
            'to_status' => TicketStatus::Pending,
            'user_id' => $template->created_by,
            'comment' => null,
        ]);

        return $instance;
    }

    /**
     * La instancia vence tantos días después de su fecha como la plantilla vence después de su inicio.
     */
    private function dueDateFor(Activity $template, string $date): ?string
    {
        if ($template->start_date === null || $template->due_date === null) {
            return null;
        }

        $days = max(0, (int) $template->start_date->diffInDays($template->due_date));

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->addDays($days)->toDateString();
    }

    /**
     * Copia responsable y colaboradores que sigan activos; omite (y registra) a quien ya no lo esté.
     */
    private function copyAssignments(Activity $template, Activity $instance): void
    {
        $assignments = $template->assignments()->with('user.roles:id,name')->orderBy('id')->get();

        foreach ($assignments as $assignment) {
            if (! $assignment->user->canAccessApplication()) {
                $this->audit->record('activities', 'assignee_skipped', $instance, null, [], [], [
                    'folio' => $instance->folio,
                    'user_id' => $assignment->user_id,
                    'role' => $assignment->role->value,
                    'reason' => 'inactive_or_without_role',
                ]);

                continue;
            }

            $instance->assignments()->save(new Assignment([
                'user_id' => $assignment->user_id,
                'role' => $assignment->role,
                'assigned_by' => $assignment->assigned_by,
            ]));
        }
    }

    /**
     * Copia las subtareas de la plantilla como NO hechas (conserva el responsable solo si sigue activo).
     */
    private function copySubtasks(Activity $template, Activity $instance): void
    {
        $subtasks = $template->subtasks()->with('assignee.roles:id,name')->orderBy('id')->get();

        if ($subtasks->isEmpty()) {
            return;
        }

        $now = now();

        Subtask::query()->insert($subtasks->map(fn (Subtask $subtask): array => [
            'activity_id' => $instance->getKey(),
            'title' => $subtask->title,
            'assigned_to' => $subtask->assignee?->canAccessApplication() ? $subtask->assigned_to : null,
            'done' => false,
            'done_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }
}
