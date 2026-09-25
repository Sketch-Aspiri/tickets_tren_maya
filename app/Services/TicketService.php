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

/**
 * Ciclo de vida del ticket: crear, editar, eliminar y cambiar de estado.
 *
 * La MÁQUINA de estados (qué transiciones existen y cuáles exigen comentario) vive en TicketStatus y se
 * aplica UNA sola vez en StatusTransitioner (compartido con las actividades); QUIÉN puede recorrer cada
 * transición lo decide TicketPolicy::transition. Ni controladores ni vistas validan transiciones.
 */
final class TicketService
{
    /** Campos que el usuario puede escribir (los mismos que el $fillable del modelo). */
    private const FORM_FIELDS = ['title', 'description', 'priority', 'category_id', 'due_date'];

    public function __construct(
        private readonly FolioGenerator $folios,
        private readonly StatusTransitioner $transitioner,
    ) {}

    /**
     * El equipo del ticket es el único del creador; con varios equipos (o alcance global) debe elegirlo
     * (`User::workTeamIdFor`).
     *
     * @param  array<string, mixed>  $data  Datos ya validados (Form Request).
     */
    public function create(User $actor, array $data): Ticket
    {
        $teamId = $actor->workTeamIdFor(isset($data['team_id']) ? (int) $data['team_id'] : null);

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
     * Cambia el estado (ver StatusTransitioner: lock, re-autorización, arista, comentario, historial y bitácora).
     */
    public function transition(User $actor, Ticket $ticket, TicketStatus $to, ?string $comment = null): Ticket
    {
        /** @var Ticket */
        return $this->transitioner->transition($actor, $ticket, $to, $comment);
    }

    /**
     * Estados a los que ESTE usuario puede pasar el ticket ahora (ocultar botones es UX; la autorización
     * real es TicketPolicy + transition()).
     *
     * @return list<TicketStatus>
     */
    public function availableTransitions(User $user, Ticket $ticket): array
    {
        return $this->transitioner->availableTransitions($user, $ticket);
    }
}
