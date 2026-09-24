<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ciclo de vida del ticket: crear, editar, eliminar y cambiar de estado.
 *
 * La MÁQUINA de estados (qué transiciones existen y cuáles exigen comentario) vive en TicketStatus y
 * se aplica UNA sola vez aquí, en `transition()`; QUIÉN puede recorrer cada transición lo decide
 * TicketPolicy::transition. Ni controladores ni vistas validan transiciones.
 */
final class TicketService
{
    private const LOG = 'tickets';

    /** Campos que el usuario puede escribir (los mismos que el $fillable del modelo). */
    private const FORM_FIELDS = ['title', 'description', 'priority', 'category_id', 'due_date'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FolioGenerator $folios,
    ) {}

    /**
     * El equipo del ticket es el del creador; un jefe (sin equipo) debe elegirlo.
     *
     * @param  array<string, mixed>  $data  Datos ya validados (Form Request).
     */
    public function create(User $actor, array $data): Ticket
    {
        $teamId = $actor->team_id ?? ($data['team_id'] ?? null);

        if ($teamId === null) {
            throw BusinessRuleException::because('tickets.errors.team_required');
        }

        return DB::transaction(function () use ($actor, $data, $teamId): Ticket {
            $ticket = new Ticket(Arr::only($data, self::FORM_FIELDS));
            $ticket->forceFill([
                'folio' => $this->folios->next((string) config('tickets.folio_prefixes.ticket')),
                'status' => TicketStatus::Pending,
                'team_id' => (int) $teamId,
                'created_by' => $actor->getKey(),
                'source' => TicketSource::Web,
                'completed_at' => null,
            ])->save();

            $ticket->statusHistories()->create([
                'from_status' => null,
                'to_status' => TicketStatus::Pending,
                'user_id' => $actor->getKey(),
                'comment' => null,
            ]);

            return $ticket;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Datos ya validados (Form Request).
     */
    public function update(Ticket $ticket, array $data): Ticket
    {
        if ($ticket->status->isFinal()) {
            throw BusinessRuleException::because('tickets.errors.closed');
        }

        $ticket->update(Arr::only($data, self::FORM_FIELDS));

        return $ticket;
    }

    /**
     * Eliminación lógica (softDeletes): solo tickets sin trabajo en curso; lo demás se cancela.
     */
    public function delete(Ticket $ticket): void
    {
        if (! in_array($ticket->status, [TicketStatus::Pending, TicketStatus::Cancelled], true)) {
            throw BusinessRuleException::because('tickets.errors.delete_state');
        }

        $ticket->delete();
    }

    /**
     * Cambia el estado. Bloquea la fila, revalida transición y permiso sobre el estado actual (dos
     * usuarios actuando a la vez no pueden pisarse), guarda historial y bitácora en una transacción.
     */
    public function transition(User $actor, Ticket $ticket, TicketStatus $to, ?string $comment = null): Ticket
    {
        $comment = $comment === null ? null : trim($comment);
        $comment = $comment === '' ? null : $comment;

        return DB::transaction(function () use ($actor, $ticket, $to, $comment): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $from = $locked->status;

            Gate::forUser($actor)->authorize('transition', [$locked, $to]);

            if (! $from->canTransitionTo($to)) {
                throw BusinessRuleException::because('tickets.errors.invalid_transition', [
                    'from' => $from->label(),
                    'to' => $to->label(),
                ]);
            }

            if ($from->requiresCommentWhenMovingTo($to) && $comment === null) {
                throw BusinessRuleException::because('tickets.errors.comment_required');
            }

            $this->persistStatus($locked, $to);

            $locked->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $actor->getKey(),
                'comment' => $comment,
            ]);

            $this->audit->record(self::LOG, 'status_changed', $locked, $actor, ['status' => $from->value], ['status' => $to->value], [
                'folio' => $locked->folio,
                'comment' => $comment,
            ]);

            return $locked;
        });
    }

    /**
     * Estados a los que ESTE usuario puede pasar el ticket ahora (para mostrar solo las acciones
     * permitidas; ocultar botones es UX, la autorización real es TicketPolicy + transition()).
     *
     * @return list<TicketStatus>
     */
    public function availableTransitions(User $user, Ticket $ticket): array
    {
        return array_values(array_filter(
            $ticket->status->allowedTargets(),
            fn (TicketStatus $target): bool => Gate::forUser($user)->allows('transition', [$ticket, $target]),
        ));
    }

    /**
     * `completed_at` solo tiene valor mientras el ticket está Completado (reabrir lo limpia). El evento
     * genérico del modelo se suprime porque se emite `status_changed` con el detalle.
     */
    private function persistStatus(Ticket $ticket, TicketStatus $to): void
    {
        $ticket->disableLogging();
        $ticket->forceFill([
            'status' => $to,
            'completed_at' => $to === TicketStatus::Completed ? now() : null,
        ])->save();
        $ticket->enableLogging();
    }
}
