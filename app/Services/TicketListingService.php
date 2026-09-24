<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listados de tickets. TODA consulta parte de `Ticket::visibleTo($user)` (alcance por rol), de modo
 * que ningún filtro puede ampliar lo que el usuario ve. Siempre paginado y con eager loading.
 */
final class TicketListingService
{
    /** Escape de LIKE portable (MySQL y SQLite): `!` como carácter de escape. */
    private const LIKE_ESCAPE = '!';

    /** Columnas por las que se permite ordenar (lista blanca; la validación vive en IndexTicketsRequest). */
    public const SORTABLE = ['created_at', 'due_date', 'priority', 'status', 'folio', 'title'];

    /**
     * @param  array{status?: ?string, priority?: ?string, category_id?: ?int, responsible_id?: ?int, team_id?: ?int, overdue?: ?bool, unassigned?: ?bool, q?: ?string, sort?: ?string, direction?: ?string}  $filters
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($user)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('tickets.status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('tickets.priority', $priority))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int $id) => $q->where('tickets.category_id', $id))
            ->when($filters['team_id'] ?? null, fn (Builder $q, int $id) => $q->where('tickets.team_id', $id))
            ->when($filters['responsible_id'] ?? null, fn (Builder $q, int $id) => $this->filterByResponsible($q, $id))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q->overdue())
            ->when($filters['unassigned'] ?? false, fn (Builder $q) => $q->unassigned())
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $this->applySearch($q, $term));

        $this->applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null);

        return $query->paginate((int) config('tickets.tickets_per_page'))->withQueryString();
    }

    /**
     * "Mis pendientes": lo asignado al usuario que sigue abierto, por vencimiento (sin fecha al final)
     * y luego por prioridad. Estructura lista para sumar actividades en el Sprint 3: bastará con unir
     * aquí la consulta equivalente de actividades y ordenar el resultado combinado.
     *
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function pendingFor(User $user): LengthAwarePaginator
    {
        $query = $this->baseQuery($user)
            ->open()
            ->whereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $user->getKey()));

        $query->orderByRaw('tickets.due_date is null')
            ->orderBy('tickets.due_date');
        $this->orderByEnum($query, 'tickets.priority', Priority::cases(), 'desc');

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
     * @return Builder<Ticket>
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $escape = self::LIKE_ESCAPE;
        $escaped = str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], $term);
        $pattern = "%{$escaped}%";

        // El fragmento SQL es constante; el termino del usuario solo viaja como binding.
        return $query->where(function (Builder $inner) use ($pattern, $escape): void {
            $inner->whereRaw("tickets.title like ? escape '{$escape}'", [$pattern])
                ->orWhereRaw("tickets.folio like ? escape '{$escape}'", [$pattern]);
        });
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applySort(Builder $query, ?string $sort, ?string $direction): void
    {
        // Se normaliza a un literal del propio codigo: nunca se interpola texto del usuario.
        $dir = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'due_date' => $query->orderByRaw('tickets.due_date is null')->orderBy('tickets.due_date', $dir),
            'priority' => $this->orderByEnum($query, 'tickets.priority', Priority::cases(), $dir),
            'status' => $this->orderByEnum($query, 'tickets.status', TicketStatus::cases(), $dir),
            'folio', 'title' => $query->orderBy('tickets.'.$sort, $dir),
            default => $query->orderBy('tickets.created_at', $dir),
        };

        $query->orderBy('tickets.id', 'desc');
    }

    /**
     * Ordena por el orden de declaracion de un enum (no alfabetico) con un CASE parametrizado.
     *
     * @param  Builder<Ticket>  $query
     * @param  list<BackedEnum>  $cases
     */
    private function orderByEnum(Builder $query, string $column, array $cases, string $dir): void
    {
        $whens = implode(' ', array_map(fn (int $index): string => 'when ? then '.($index + 1), array_keys($cases)));
        $bindings = array_map(fn (BackedEnum $case): string|int => $case->value, $cases);
        $direction = $dir === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw("case {$column} {$whens} else 0 end {$direction}", $bindings);
    }
}
