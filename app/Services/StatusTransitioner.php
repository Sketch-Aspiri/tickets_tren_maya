<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use App\Support\WorkflowSubject;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * La ÚNICA implementación de "cambiar de estado" para tickets y actividades (sección 6 de CLAUDE.md).
 *
 * El grafo de transiciones vive en TicketStatus y QUIÉN puede recorrer cada arista lo decide la Policy del
 * registro (`transition`); aquí se aplica el algoritmo una sola vez: bloquear la fila, volver a autorizar
 * contra el estado fresco (dos usuarios actuando a la vez no pueden pisarse), validar la arista, exigir
 * comentario en rechazo y reapertura, dejar historial y bitácora en la misma transacción.
 */
final class StatusTransitioner
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `$guard` son reglas propias del tipo (p. ej. subtareas pendientes) que se revisan bajo el lock una vez
     * validada la arista; recibe el registro fresco, el estado actual y el destino, y lanza si no procede.
     *
     * @param  Closure(Ticket|Activity, TicketStatus, TicketStatus): void|null  $guard
     */
    public function transition(User $actor, Ticket|Activity $subject, TicketStatus $to, ?string $comment = null, ?Closure $guard = null): Ticket|Activity
    {
        $comment = $comment === null ? null : trim($comment);
        $comment = $comment === '' ? null : $comment;

        return DB::transaction(function () use ($actor, $subject, $to, $comment, $guard): Ticket|Activity {
            $locked = $subject::query()->lockForUpdate()->findOrFail($subject->getKey());
            $from = $locked->status;

            Gate::forUser($actor)->authorize('transition', [$locked, $to]);

            if (! $from->canTransitionTo($to)) {
                throw BusinessRuleException::because(WorkflowSubject::error($locked, 'invalid_transition'), [
                    'from' => $from->label(),
                    'to' => $to->label(),
                ]);
            }

            if ($from->requiresCommentWhenMovingTo($to) && $comment === null) {
                throw BusinessRuleException::because(WorkflowSubject::error($locked, 'comment_required'));
            }

            if ($guard !== null) {
                $guard($locked, $from, $to);
            }

            $this->persistStatus($locked, $to);

            $locked->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $actor->getKey(),
                'comment' => $comment,
            ]);

            $this->audit->record(WorkflowSubject::group($locked), 'status_changed', $locked, $actor, ['status' => $from->value], ['status' => $to->value], [
                'folio' => $locked->folio,
                'comment' => $comment,
            ]);

            return $locked;
        });
    }

    /**
     * Estados a los que ESTE usuario puede pasar el registro ahora (para mostrar solo las acciones
     * permitidas; ocultar botones es UX, la autorización real es la Policy + transition()).
     *
     * @return list<TicketStatus>
     */
    public function availableTransitions(User $user, Ticket|Activity $subject): array
    {
        return array_values(array_filter(
            $subject->status->allowedTargets(),
            fn (TicketStatus $target): bool => Gate::forUser($user)->allows('transition', [$subject, $target]),
        ));
    }

    /**
     * `completed_at` solo tiene valor mientras el registro está Completado (reabrir lo limpia). El evento
     * genérico del modelo se suprime porque se emite `status_changed` con el detalle.
     */
    private function persistStatus(Ticket|Activity $subject, TicketStatus $to): void
    {
        $subject->disableLogging();
        $subject->forceFill([
            'status' => $to,
            'completed_at' => $to === TicketStatus::Completed ? now() : null,
        ])->save();
        $subject->enableLogging();
    }
}
