<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\WorkItemCommented;
use App\Notifications\WorkItemRejected;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avisos de tickets y actividades: comentario nuevo (→ asignados y creador) y rechazo en revisión (→ asignados).
 * Nunca se avisa a quien ejecuta la acción ni a cuentas que ya no están activas.
 */
final class WorkItemNotifier
{
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
