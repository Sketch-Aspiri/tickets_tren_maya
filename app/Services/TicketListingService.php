<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentRole;
use App\Enums\PendingScope;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ListingQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Listados de tickets. TODA consulta parte de `Ticket::visibleTo($user)` (alcance por rol), de modo
 * que ningún filtro puede ampliar lo que el usuario ve. Siempre paginado y con eager loading.
 */
final class TicketListingService
{
    /** Columnas por las que se permite ordenar (lista blanca; la validación vive en IndexTicketsRequest). */
    public const SORTABLE = ['created_at', 'due_date', 'priority', 'status', 'folio', 'title'];

    /**
     * @param  array{status?: ?string, priority?: ?string, category_id?: ?int, responsible_id?: ?int, team_id?: ?int, overdue?: ?bool, unassigned?: ?bool, q?: ?string, sort?: ?string, direction?: ?string}  $filters
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->filtered($user, $filters);

        $this->applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null);

        return $query->paginate((int) config('tickets.tickets_per_page'))->withQueryString();
    }

    /**
     * Los MISMOS filtros, alcance y orden del listado, sin paginar y acotado a `$limit` filas (exportacion).
     *
     * @param  array{status?: ?string, priority?: ?string, category_id?: ?int, responsible_id?: ?int, team_id?: ?int, overdue?: ?bool, unassigned?: ?bool, q?: ?string, sort?: ?string, direction?: ?string}  $filters
     * @return Collection<int, Ticket>
     */
    public function limited(User $user, array $filters, int $limit): Collection
    {
        $query = $this->filtered($user, $filters);

        $this->applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null);

        return $query->limit($limit)->get();
    }

    /**
     * Alcance por rol (`visibleTo`) + filtros validados. Ningun filtro puede ensanchar el alcance.
     *
     * @param  array{status?: ?string, priority?: ?string, category_id?: ?int, responsible_id?: ?int, team_id?: ?int, overdue?: ?bool, unassigned?: ?bool, q?: ?string}  $filters
     * @return Builder<Ticket>
     */
    private function filtered(User $user, array $filters): Builder
    {
        return $this->baseQuery($user)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('tickets.status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('tickets.priority', $priority))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int $id) => $q->where('tickets.category_id', $id))
            ->when($filters['team_id'] ?? null, fn (Builder $q, int $id) => $q->where('tickets.team_id', $id))
            ->when($filters['responsible_id'] ?? null, fn (Builder $q, int $id) => $this->filterByResponsible($q, $id))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q->overdue())
            ->when($filters['unassigned'] ?? false, fn (Builder $q) => $q->unassigned())
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => ListingQuery::search($q, $term, ['tickets.title', 'tickets.folio']));
    }

    /**
     * "Mis pendientes" (sección de tickets): lo asignado al usuario que sigue abierto, por vencimiento (sin
     * fecha al final) y luego por prioridad. Las actividades van en su propia sección
     * (ActivityListingService::pendingFor), con paginación independiente.
     *
     * Con `PendingScope::Team` (solo coordinadores con equipo; para cualquiera mas se ignora) lista en cambio lo
     * abierto de los equipos del coordinador, asignado o no (incluye la bolsa), siempre dentro de `visibleTo`.
     *
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function pendingFor(User $user, PendingScope $scope = PendingScope::Mine): LengthAwarePaginator
    {
        $query = $this->baseQuery($user)->open();

        if ($scope === PendingScope::Team && PendingScope::canUseTeam($user)) {
            $query->whereIn('tickets.team_id', $user->teamIdsQuery());
        } else {
            $query->whereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $user->getKey()));
        }

        ListingQuery::orderByDateNullsLast($query, 'tickets.due_date', 'asc');
        ListingQuery::orderByEnum($query, 'tickets.priority', Priority::cases(), 'desc');

        return $query->orderBy('tickets.id')
            ->paginate((int) config('tickets.tickets_per_page'))
            ->withQueryString();
    }

    /**
     * @return Builder<Ticket>
     */
    private function baseQuery(User $user): Builder
    {
        return Ticket::query()
            ->visibleTo($user)
            ->with(['category:id,name', 'team:id,name', 'assignments.user:id,name']);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private function filterByResponsible(Builder $query, int $userId): Builder
    {
        return $query->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->where('user_id', $userId)
            ->where('role', AssignmentRole::Responsable->value));
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applySort(Builder $query, ?string $sort, ?string $direction): void
    {
        // Se normaliza a un literal del propio codigo: nunca se interpola texto del usuario.
        $dir = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'due_date' => ListingQuery::orderByDateNullsLast($query, 'tickets.due_date', $dir),
            'priority' => ListingQuery::orderByEnum($query, 'tickets.priority', Priority::cases(), $dir),
            'status' => ListingQuery::orderByEnum($query, 'tickets.status', TicketStatus::cases(), $dir),
            'folio', 'title' => $query->orderBy('tickets.'.$sort, $dir),
            default => $query->orderBy('tickets.created_at', $dir),
        };

        $query->orderBy('tickets.id', 'desc');
    }
}
