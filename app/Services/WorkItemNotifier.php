<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketArrivedInPool;
use App\Notifications\WorkItemCommented;
use App\Notifications\WorkItemRejected;
use App\Notifications\WorkItemStatusChanged;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avisos de tickets y actividades: comentario nuevo (→ asignados y creador) y rechazo en revisión (→ asignados).
 * Nunca se avisa a quien ejecuta la acción ni a cuentas que ya no están activas.
 */
final class WorkItemNotifier
{
    /**
     * Ticket nuevo en la bolsa de un equipo: avisa al coordinador del equipo y a quienes aprueban en su coordinacion
     * (sus jefes, o los administradores si no tiene jefe activo). Nunca a quien lo creo.
     */
    public function arrivedInPool(User $creator, Ticket $ticket): void
    {
        $ticket->loadMissing('team.coordination');
        $team = $ticket->team;

        if ($team === null) {
            return;
        }

        $recipients = $team->coordination?->approvers() ?? new Collection;

        if ($team->coordinator_id !== null) {
            $coordinator = User::query()->find($team->coordinator_id);

            if ($coordinator !== null) {
                $recipients->push($coordinator);
            }
        }

        $recipients
            ->unique('id')
            ->reject(fn (User $user): bool => $user->is($creator) || ! $user->canAccessApplication())
            ->each(fn (User $user) => $user->notify(new TicketArrivedInPool(
                (int) $ticket->getKey(),
                (string) $ticket->folio,
                (string) $ticket->title,
                (string) $team->name,
                (string) $creator->name,
            )));
    }

    public function commented(User $author, Ticket|Activity $item): void
    {
        $recipients = $this->recipients($item, $author, includeCreator: true);

        foreach ($recipients as $user) {
            $user->notify(new WorkItemCommented(
                $item instanceof Activity ? 'activity' : 'ticket',
                (int) $item->getKey(),
                (string) $item->folio,
                (string) $item->title,
                $author->name,
            ));
        }
    }

    public function statusChanged(User $actor, Ticket|Activity $item, TicketStatus $to): void
    {
        foreach ($this->recipients($item, $actor, includeCreator: true) as $user) {
            $user->notify(new WorkItemStatusChanged(
                $item instanceof Activity ? 'activity' : 'ticket',
                (int) $item->getKey(),
                (string) $item->folio,
                $to->label(),
                $actor->name,
            ));
        }
    }

    public function rejected(User $reviewer, Ticket|Activity $item): void
    {
        $recipients = $this->recipients($item, $reviewer, includeCreator: false);

        foreach ($recipients as $user) {
            $user->notify(new WorkItemRejected(
                $item instanceof Activity ? 'activity' : 'ticket',
                (int) $item->getKey(),
                (string) $item->folio,
                (string) $item->title,
                $reviewer->name,
            ));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Ticket|Activity $item, User $actor, bool $includeCreator): Collection
    {
        $ids = $item->assignments()->pluck('user_id')->all();

        if ($includeCreator && $item->created_by !== null) {
            $ids[] = $item->created_by;
        }

        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), [(int) $actor->getKey()]));

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->whereKey($ids)
            ->where('status', UserStatus::Active->value)
            ->get();
    }
}
