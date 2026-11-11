<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Enums\PendingScope;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Ticket;
use App\Services\ActivityListingService;
use App\Services\TicketListingService;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resumen de "Mis pendientes" que se comparte con el Dashboard por el puente (decision 89). Parte de las MISMAS
 * consultas que la vista (`pendingQuery`, alcance `mine`), asi que nunca muestra algo que la persona no veria
 * aqui. Solo se responde por cuentas activas, con rol y vinculadas con el Dashboard (`sso_linked_at`, decision
 * 88): el registro de Tickets no verifica correos, el correo solo no prueba que sea la misma persona.
 */
final class PendingSummaryService
{
    private const URGENT_LIMIT = 5;

    public function __construct(
        private readonly TicketListingService $tickets,
        private readonly ActivityListingService $activities,
        private readonly SingleSignOnService $sso,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $email): array
    {
        $user = $this->sso->accountFor($email);

        if ($user === null || ! $this->sso->isLinked($user)) {
            return ['linked' => false];
        }

        $tickets = $this->tickets->pendingQuery($user, PendingScope::Mine);
        $activities = $this->activities->pendingQuery($user, PendingScope::Mine);

        $urgent = $this->urgent($tickets, 'ticket', 'tickets.show')
            ->concat($this->urgent($activities, 'activity', 'activities.show'))
            ->sortBy(fn (array $row): array => [$row['due_date'] === null ? 1 : 0, (string) $row['due_date'], -$row['priority_weight']])
            ->take(self::URGENT_LIMIT)
            ->map(fn (array $row): array => array_diff_key($row, ['priority_weight' => true]))
            ->values()
            ->all();

        return [
            'linked' => true,
            'counts' => [
                'tickets' => $this->count($tickets),
                'activities' => $this->count($activities),
                'overdue' => $this->count($tickets, fn (Builder $q) => $q->overdue()) + $this->count($activities, fn (Builder $q) => $q->overdue()),
                'in_review' => $this->count($tickets, fn (Builder $q) => $q->where('tickets.status', TicketStatus::InReview->value))
                    + $this->count($activities, fn (Builder $q) => $q->where('activities.status', TicketStatus::InReview->value)),
            ],
            'urgent' => $urgent,
            'pending_url' => route('tickets.pending', absolute: false),
        ];
    }

    /**
     * @param  Builder<Ticket>|Builder<Activity>  $query
     */
    private function count(Builder $query, ?callable $extra = null): int
    {
        $query = (clone $query)->reorder()->setEagerLoads([]);

        if ($extra !== null) {
            $extra($query);
        }

        return $query->count();
    }

    /**
     * Los primeros de cada tipo, ya en el orden de "Mis pendientes" (vencimiento, sin fecha al final, prioridad).
     *
     * @param  Builder<Ticket>|Builder<Activity>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function urgent(Builder $query, string $type, string $route): Collection
    {
        $today = LocalTime::today();

        return (clone $query)->setEagerLoads([])->limit(self::URGENT_LIMIT)
            ->get(['id', 'folio', 'title', 'priority', 'status', 'due_date'])
            ->map(fn (Ticket|Activity $item): array => [
                'type' => $type,
                'folio' => (string) $item->folio,
                'title' => (string) $item->title,
                'status' => $item->status->label(),
                'priority' => $item->priority->label(),
                'priority_weight' => array_search($item->priority, Priority::cases(), true),
                'due_date' => $item->due_date?->toDateString(),
                'overdue' => $item->due_date !== null && $item->due_date->toDateString() < $today,
                'url' => route($route, $item, absolute: false),
            ]);
    }
}
