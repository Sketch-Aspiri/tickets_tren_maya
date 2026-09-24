<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use App\Support\RecurrenceRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ciclo de vida de la actividad: crear (con asignación y, si es recurrente, sus primeras instancias), editar,
 * eliminar y cambiar de estado.
 *
 * Usa la MISMA máquina de estados que el ticket (TicketStatus, aplicada por StatusTransitioner); QUIÉN puede
 * recorrer cada transición lo decide ActivityPolicy::transition. Lo propio de las actividades vive aquí:
 * - una PLANTILLA de recurrencia solo se cancela y se reabre (nunca se "trabaja"; sus instancias sí);
 * - no se envía a revisión ni se aprueba con subtareas pendientes.
 */
final class ActivityService
{
    /** Campos que el usuario puede escribir (los mismos que el $fillable del modelo). */
    private const FORM_FIELDS = ['title', 'description', 'priority', 'category_id', 'start_date', 'due_date'];

    /** Lo único que se puede hacer con una plantilla en cuanto a estado. */
    private const TEMPLATE_TARGETS = [TicketStatus::Cancelled, TicketStatus::Pending];

    public function __construct(
        private readonly FolioGenerator $folios,
        private readonly AssignmentService $assignments,
        private readonly RecurrenceService $recurrence,
        private readonly StatusTransitioner $transitioner,
    ) {}

    /**
     * El equipo de la actividad es el del creador (coordinador); un jefe (sin equipo) debe elegirlo. Todo va en
     * una transacción: si la asignación es inválida, no queda una actividad a medias.
     *
     * @param  array<string, mixed>  $data  Datos ya validados (Form Request): campos del formulario,
     *                                      `responsible_id`, `collaborator_ids`, `team_id` (jefe) y, si es
     *                                      recurrente, `is_recurring` + `recurrence`.
     */
    public function create(User $actor, array $data): Activity
    {
        $teamId = $actor->team_id ?? ($data['team_id'] ?? null);

        if ($teamId === null) {
            throw BusinessRuleException::because('activities.errors.team_required');
        }

        return DB::transaction(function () use ($actor, $data, $teamId): Activity {
            $rule = $this->ruleFrom($data);

            $activity = new Activity(Arr::only($data, self::FORM_FIELDS));
            $activity->forceFill([
                'folio' => $this->folios->next((string) config('tickets.folio_prefixes.activity')),
                'status' => TicketStatus::Pending,
                'team_id' => (int) $teamId,
                'created_by' => $actor->getKey(),
                'recurrence_rule' => $rule,
                'completed_at' => null,
            ])->save();

            $activity->statusHistories()->create([
                'from_status' => null,
                'to_status' => TicketStatus::Pending,
                'user_id' => $actor->getKey(),
                'comment' => null,
            ]);

            $this->assignments->assign(
                $actor,
                $activity,
                (int) $data['responsible_id'],
                array_map('intval', (array) ($data['collaborator_ids'] ?? [])),
            );

            if ($rule !== null) {
                $this->recurrence->generateFor($activity);
            }

            return $activity;
        });
    }

    /**
     * Editar una plantilla cambia la regla y los datos que heredarán las instancias FUTURAS; las ya generadas
     * no se tocan (son independientes). Una actividad normal o una instancia nunca se vuelve recurrente.
     *
     * @param  array<string, mixed>  $data  Datos ya validados (Form Request).
     */
    public function update(Activity $activity, array $data): Activity
    {
        if ($activity->status->isFinal()) {
            throw BusinessRuleException::because('activities.errors.closed');
        }

        $activity->fill(Arr::only($data, self::FORM_FIELDS));

        if ($activity->isTemplate()) {
            $activity->recurrence_rule = $this->ruleFrom([...$data, 'is_recurring' => true]);
        }

        $activity->save();

        return $activity;
    }

    /**
     * Eliminación lógica (softDeletes): solo actividades sin trabajo en curso; lo demás se cancela.
     */
    public function delete(Activity $activity): void
    {
        if (! in_array($activity->status, [TicketStatus::Pending, TicketStatus::Cancelled], true)) {
            throw BusinessRuleException::because('activities.errors.delete_state');
        }

        $activity->delete();
    }

    /**
     * Cambia el estado (ver StatusTransitioner) más las reglas propias de las actividades.
     */
    public function transition(User $actor, Activity $activity, TicketStatus $to, ?string $comment = null): Activity
    {
        Gate::forUser($actor)->authorize('transition', [$activity, $to]);

        if ($activity->isTemplate() && ! in_array($to, self::TEMPLATE_TARGETS, true)) {
            throw BusinessRuleException::because('activities.errors.template_transition');
        }

        /** @var Activity */
        return $this->transitioner->transition(
            $actor,
            $activity,
            $to,
            $comment,
            fn (Ticket|Activity $locked, TicketStatus $from, TicketStatus $target) => $this->assertNoPendingSubtasks($locked, $target),
        );
    }

    /**
     * Estados a los que ESTE usuario puede pasar la actividad ahora (ocultar botones es UX; la autorización
     * real es ActivityPolicy + transition()). Una plantilla solo se cancela o se reabre.
     *
     * @return list<TicketStatus>
     */
    public function availableTransitions(User $user, Activity $activity): array
    {
        $targets = $this->transitioner->availableTransitions($user, $activity);

        if (! $activity->isTemplate()) {
            return $targets;
        }

        return array_values(array_filter($targets, fn (TicketStatus $target): bool => in_array($target, self::TEMPLATE_TARGETS, true)));
    }

    /**
     * Enviar a revisión o aprobar con subtareas sin hacer dejaría el avance inconsistente. Rechazar o
     * cancelar sí se puede en cualquier momento.
     */
    private function assertNoPendingSubtasks(Ticket|Activity $subject, TicketStatus $to): void
    {
        if (! $subject instanceof Activity || ! in_array($to, [TicketStatus::InReview, TicketStatus::Completed], true)) {
            return;
        }

        $pending = $subject->subtasks()->where('done', false)->count();

        if ($pending > 0) {
            throw BusinessRuleException::because('activities.errors.pending_subtasks', ['count' => $pending]);
        }
    }

    /**
     * Regla normalizada (o null si la actividad no es recurrente). Una regla inválida que llegara aquí sin
     * pasar por el Form Request se rechaza con un mensaje de negocio, no con un error del sistema.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function ruleFrom(array $data): ?array
    {
        if (! (bool) ($data['is_recurring'] ?? false)) {
            return null;
        }

        try {
            return RecurrenceRule::fromArray((array) ($data['recurrence'] ?? []))->toArray();
        } catch (\InvalidArgumentException) {
            throw BusinessRuleException::because('activities.errors.invalid_recurrence');
        }
    }
}
