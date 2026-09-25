<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentRole;
use App\Enums\PendingScope;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\User;
use App\Support\ListingQuery;
use App\Support\LocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Listados de actividades. TODA consulta parte de `Activity::visibleTo($user)` (alcance por rol), de modo que
 * ningún filtro puede ampliar lo que el usuario ve. Siempre paginado, con eager loading y con el avance
 * calculado por subconsultas (`withProgress`), sin cargar subtareas.
 */
final class ActivityListingService
{
    /** Columnas por las que se permite ordenar (lista blanca; la validación vive en IndexActivitiesRequest). */
    public const SORTABLE = ['created_at', 'due_date', 'priority', 'status', 'folio', 'title'];

    /** Tipos de actividad del filtro `kind`: normal, plantilla de recurrencia o instancia generada. */
    public const KINDS = ['single', 'template', 'instance'];

    /**
     * @param  array{status?: ?string, priority?: ?string, category_id?: ?int, responsible_id?: ?int, team_id?: ?int, overdue?: ?bool, due_today?: ?bool, kind?: ?string, q?: ?string, sort?: ?string, direction?: ?string}  $filters
     * @return LengthAwarePaginator<int, Activity>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->filtered($user, $filters);

        $this->applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null);

        return $query->paginate((int) config('tickets.activities_per_page'))->withQueryString();
    }

    /**
     * Los MISMOS filtros, alcance y orden del listado, sin paginar y acotado a `$limit` filas (exportacion).
     *
     * @param  array{status?: ?string, priority?: ?string, category_id?: ?int, responsible_id?: ?int, team_id?: ?int, overdue?: ?bool, due_today?: ?bool, kind?: ?string, q?: ?string, sort?: ?string, direction?: ?string}  $filters
     * @return Collection<int, Activity>
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
     * @param  array{status?: ?string, priority?: ?string, category_id?: ?int, responsible_id?: ?int, team_id?: ?int, overdue?: ?bool, due_today?: ?bool, kind?: ?string, q?: ?string}  $filters
     * @return Builder<Activity>
     */
    private function filtered(User $user, array $filters): Builder
    {
        return $this->baseQuery($user)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('activities.status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('activities.priority', $priority))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int $id) => $q->where('activities.category_id', $id))
            ->when($filters['team_id'] ?? null, fn (Builder $q, int $id) => $q->where('activities.team_id', $id))
            ->when($filters['responsible_id'] ?? null, fn (Builder $q, int $id) => $this->filterByResponsible($q, $id))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q->overdue())
            ->when($filters['due_today'] ?? false, fn (Builder $q) => $this->filterDueToday($q))
            ->when($filters['kind'] ?? null, fn (Builder $q, string $kind) => $this->filterByKind($q, $kind))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => ListingQuery::search($q, $term, ['activities.title', 'activities.folio']));
    }

    /**
     * "Mis pendientes" (sección de actividades): actividades abiertas asignadas al usuario (o con una subtarea
     * suya sin hacer), sin plantillas de recurrencia (solo sus instancias), por vencimiento (sin fecha al
     * final) y luego por prioridad. Página propia (`activities_page`) para no chocar con la de tickets.
     *
     * Con `PendingScope::Team` (solo coordinadores con equipo; para cualquiera mas se ignora) lista en cambio las
     * actividades abiertas de los equipos del coordinador (sin plantillas), siempre dentro de `visibleTo`.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function pendingFor(User $user, PendingScope $scope = PendingScope::Mine): LengthAwarePaginator
    {
        $query = $this->baseQuery($user)
            ->withoutTemplates()
            ->open();

        if ($scope === PendingScope::Team && PendingScope::canUseTeam($user)) {
            $query->whereIn('activities.team_id', $user->teamIdsQuery());
        } else {
            $query->where(fn (Builder $mine) => $mine
                ->assignedTo($user)
                ->orWhere(fn (Builder $subtask) => $subtask->hasSubtaskFor($user, true)));
        }

        ListingQuery::orderByDateNullsLast($query, 'activities.due_date', 'asc');
        ListingQuery::orderByEnum($query, 'activities.priority', Priority::cases(), 'desc');

        return $query->orderBy('activities.id')
            ->paginate((int) config('tickets.activities_per_page'), pageName: 'activities_page')
            ->withQueryString();
    }

    /**
     * @return Builder<Activity>
     */
    private function baseQuery(User $user): Builder
    {
        return Activity::query()
            ->visibleTo($user)
            ->withProgress()
            ->with(['category:id,name', 'team:id,name', 'parent:id,folio', 'assignments.user:id,name']);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function filterByResponsible(Builder $query, int $userId): Builder
    {
        return $query->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->where('user_id', $userId)
            ->where('role', AssignmentRole::Responsable->value));
    }

    /**
     * Actividades ABIERTAS cuya fecha limite es hoy (hora de negocio). Las plantillas de recurrencia no cuentan:
     * su trabajo lo hacen sus instancias. `whereDate` porque en SQLite un `date` se guarda como `Y-m-d 00:00:00`.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function filterDueToday(Builder $query): Builder
    {
        return $query->open()->withoutTemplates()->whereDate('activities.due_date', LocalTime::today());
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function filterByKind(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            'template' => $query->templates(),
            'instance' => $query->whereNotNull('activities.parent_activity_id'),
            'single' => $query->withoutTemplates()->whereNull('activities.parent_activity_id'),
            default => $query,
        };
    }

    /**
     * @param  Builder<Activity>  $query
     */
    private function applySort(Builder $query, ?string $sort, ?string $direction): void
    {
        // Por defecto se ordena por fecha limite (la mas proxima primero, sin fecha al final), no por creacion.
        // La direccion se normaliza a un literal del propio codigo: nunca se interpola texto del usuario.
        $sort ??= 'due_date';
        $dir = $direction ?? ($sort === 'due_date' ? 'asc' : 'desc');
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'due_date' => ListingQuery::orderByDateNullsLast($query, 'activities.due_date', $dir),
            'priority' => ListingQuery::orderByEnum($query, 'activities.priority', Priority::cases(), $dir),
            'status' => ListingQuery::orderByEnum($query, 'activities.status', TicketStatus::cases(), $dir),
            'folio', 'title' => $query->orderBy('activities.'.$sort, $dir),
            default => $query->orderBy('activities.created_at', $dir),
        };

        $query->orderBy('activities.created_at', 'desc')->orderBy('activities.id', 'desc');
    }
}
