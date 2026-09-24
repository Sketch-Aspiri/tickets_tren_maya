<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Dashboard\DashboardFilters;
use App\Support\LocalTime;
use App\Support\SqlExpressions;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;

/**
 * Métricas del panel de seguimiento (sección 11 de CLAUDE.md). Cuenta TICKETS Y ACTIVIDADES (con desglose
 * por tipo; las plantillas de recurrencia y lo eliminado no cuentan) sobre el alcance que define DashboardScope.
 *
 * Definiciones (decisión 46):
 * - Abiertos, en revisión y vencidos: FOTO de hoy (no dependen del periodo). Vencido = cálculo, nunca estado.
 * - Completados: estado Completado con `completed_at` dentro del periodo.
 * - Distribución por estado/prioridad y tendencia: registros CREADOS en el periodo (tendencia: contra los
 *   completados por día).
 * - Carga por persona: asignaciones (responsable o colaborador) sobre lo abierto hoy.
 * - Tiempo de cierre: promedio de `completed_at - created_at` de lo completado en el periodo.
 *
 * Todo con consultas agregadas (COUNT/SUM/GROUP BY): nunca se cargan registros completos y el número de
 * consultas NO crece con los datos. El resultado son solo arreglos de escalares (cacheable).
 */
final class DashboardMetricsService
{
    public function __construct(private readonly DashboardScope $scope) {}

    /**
     * Cache corto por usuario + rol + equipo + filtros: un resultado nunca se sirve a otro alcance.
     *
     * @return array<string, mixed>
     */
    public function report(User $user, DashboardFilters $filters): array
    {
        $ttl = (int) config('tickets.dashboard.cache_ttl');

        if ($ttl <= 0) {
            return $this->compute($user, $filters);
        }

        return Cache::remember($this->cacheKey($user, $filters), $ttl, fn (): array => $this->compute($user, $filters));
    }

    public function cacheKey(User $user, DashboardFilters $filters): string
    {
        $identity = [
            'user' => $user->getKey(),
            'role' => $user->roleEnum()?->value,
            'team' => $user->team_id,
            'day' => LocalTime::today(),
            'filters' => $filters->cacheKey(),
        ];

        return 'dashboard:report:v1:'.sha1((string) json_encode($identity));
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(User $user, DashboardFilters $filters): array
    {
        $builders = [
            'tickets' => $this->scope->tickets($user, $filters),
            'activities' => $this->scope->activities($user, $filters),
        ];
        $granularity = $filters->days() > (int) config('tickets.dashboard.weekly_trend_after_days') ? 'week' : 'day';
        $teamId = $this->scope->effectiveTeamId($user, $filters);

        return [
            'period' => ['from' => $filters->from, 'to' => $filters->to, 'days' => $filters->days()],
            'scope' => ['team_id' => $teamId, 'team_name' => $teamId === null ? null : Team::query()->whereKey($teamId)->value('name')],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'cards' => $this->cards($builders, $filters),
            'created_distribution' => $this->distribution($builders, $filters),
            'trend' => ['granularity' => $granularity, 'points' => $this->trend($builders, $filters, $granularity)],
            'workload' => $this->workload($builders, $filters),
            'closing' => $this->closing($builders, $filters),
        ];
    }

    // --- Tarjetas -----------------------------------------------------------

    /**
     * Una consulta por tipo con agregados condicionales. Cada tarjeta = ['tickets' => n, 'activities' => n, 'total' => n].
     *
     * @param  array<string, Builder<Ticket>|Builder<Activity>>  $builders
     * @return array<string, array<string, int>>
     */
    private function cards(array $builders, DashboardFilters $filters): array
    {
        $finals = TicketStatus::finalValues();
        $cards = ['open' => [], 'in_review' => [], 'overdue' => [], 'completed' => []];

        foreach ($builders as $type => $builder) {
            $row = (clone $builder)->toBase()->selectRaw(
                "coalesce(sum(case when {$type}.status not in (?, ?) then 1 else 0 end), 0) as open_count,
                 coalesce(sum(case when {$type}.status = ? then 1 else 0 end), 0) as review_count,
                 coalesce(sum(case when {$type}.due_date is not null and {$type}.due_date < ? and {$type}.status not in (?, ?) then 1 else 0 end), 0) as overdue_count,
                 coalesce(sum(case when {$type}.status = ? and {$type}.completed_at >= ? and {$type}.completed_at < ? then 1 else 0 end), 0) as completed_count",
                [
                    ...$finals,
                    TicketStatus::InReview->value,
                    LocalTime::today(), ...$finals,
                    TicketStatus::Completed->value, $filters->startsAt(), $filters->endsBefore(),
                ],
            )->first();

            $cards['open'][$type] = (int) $row->open_count;
            $cards['in_review'][$type] = (int) $row->review_count;
            $cards['overdue'][$type] = (int) $row->overdue_count;
            $cards['completed'][$type] = (int) $row->completed_count;
        }

        return array_map(fn (array $card): array => $this->withTotal($card), $cards);
    }

    // --- Distribuciones -----------------------------------------------------

    /**
     * Lo creado en el periodo, por su estado ACTUAL y por prioridad (una consulta por tipo).
     *
     * @param  array<string, Builder<Ticket>|Builder<Activity>>  $builders
     * @return array{by_status: list<array<string, int|string>>, by_priority: list<array<string, int|string>>, total: int}
     */
    private function distribution(array $builders, DashboardFilters $filters): array
    {
        $status = $this->zeroed(array_map(fn (TicketStatus $case): string => $case->value, TicketStatus::cases()));
        $priority = $this->zeroed(Priority::values());

        foreach ($builders as $type => $builder) {
            $rows = $this->createdInPeriod($builder, $type, $filters)
                ->select(["{$type}.status as status", "{$type}.priority as priority"])
                ->selectRaw('count(*) as total')
                ->groupBy("{$type}.status", "{$type}.priority")
                ->get();

            foreach ($rows as $row) {
                $status[$this->raw($row->status)][$type] += (int) $row->total;
                $priority[$this->raw($row->priority)][$type] += (int) $row->total;
            }
        }

        $byStatus = $this->flatten($status);

        return [
            'by_status' => $byStatus,
            'by_priority' => $this->flatten($priority),
            'total' => array_sum(array_column($byStatus, 'total')),
        ];
    }

    // --- Tendencia ----------------------------------------------------------

    /**
     * Creados vs completados por día (o por semana ISO si el periodo es largo). Puntos sin datos en cero.
     *
     * @param  array<string, Builder<Ticket>|Builder<Activity>>  $builders
     * @return list<array{label: string, created: int, completed: int}>
     */
    private function trend(array $builders, DashboardFilters $filters, string $granularity): array
    {
        $offset = SqlExpressions::displayOffsetMinutes(CarbonImmutable::parse($filters->to, (string) config('app.display_timezone')));
        $points = $this->emptyBuckets($filters, $granularity);

        foreach ($builders as $type => $builder) {
            $created = SqlExpressions::localDate("{$type}.created_at", $offset);
            $completed = SqlExpressions::localDate("{$type}.completed_at", $offset);

            $createdRows = $this->createdInPeriod($builder, $type, $filters)
                ->selectRaw("{$created} as bucket, count(*) as total")->groupBy('bucket')->get();
            $completedRows = $this->completedInPeriod($builder, $type, $filters)
                ->selectRaw("{$completed} as bucket, count(*) as total")->groupBy('bucket')->get();

            $this->accumulate($points, $createdRows, 'created', $granularity);
            $this->accumulate($points, $completedRows, 'completed', $granularity);
        }

        $result = [];
        foreach ($points as $label => $counts) {
            $result[] = ['label' => $label, ...$counts];
        }

        return $result;
    }

    /**
     * @return array<string, array{created: int, completed: int}>
     */
    private function emptyBuckets(DashboardFilters $filters, string $granularity): array
    {
        $buckets = [];
        $cursor = CarbonImmutable::parse($filters->from);
        $end = CarbonImmutable::parse($filters->to);

        while ($cursor->lessThanOrEqualTo($end)) {
            $buckets[$this->bucketLabel($cursor->toDateString(), $granularity)] = ['created' => 0, 'completed' => 0];
            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    /**
     * @param  array<string, array{created: int, completed: int}>  $points
     * @param  iterable<object>  $rows
     */
    private function accumulate(array &$points, iterable $rows, string $key, string $granularity): void
    {
        foreach ($rows as $row) {
            $label = $this->bucketLabel((string) $row->bucket, $granularity);

            if (isset($points[$label])) {
                $points[$label][$key] += (int) $row->total;
            }
        }
    }

    /**
     * Etiqueta del punto: el día (Y-m-d) o el lunes de su semana ISO (Y-m-d).
     */
    private function bucketLabel(string $date, string $granularity): string
    {
        $day = CarbonImmutable::parse($date);

        return ($granularity === 'week' ? $day->startOfWeek() : $day)->toDateString();
    }

    // --- Carga por persona --------------------------------------------------

    /**
     * Pendientes por empleado: asignaciones sobre lo ABIERTO hoy (una consulta por tipo + una de nombres).
     *
     * @param  array<string, Builder<Ticket>|Builder<Activity>>  $builders
     * @return array{rows: list<array<string, int|string>>, total_people: int}
     */
    private function workload(array $builders, DashboardFilters $filters): array
    {
        $people = [];

        foreach ($builders as $type => $builder) {
            foreach ($this->assignmentsOverOpen($builder, $type, $filters)->get() as $row) {
                $userId = (int) $row->user_id;
                $people[$userId] ??= ['tickets_open' => 0, 'activities_open' => 0, 'in_review' => 0, 'overdue' => 0];
                $people[$userId][$type.'_open'] += (int) $row->open_count;
                $people[$userId]['in_review'] += (int) $row->review_count;
                $people[$userId]['overdue'] += (int) $row->overdue_count;
            }
        }

        $names = User::query()->whereIn('id', array_keys($people))->pluck('name', 'id');
        $rows = [];
        foreach ($people as $userId => $counts) {
            $rows[] = [
                'user_id' => $userId,
                'name' => (string) ($names[$userId] ?? '#'.$userId),
                ...$counts,
                'open' => $counts['tickets_open'] + $counts['activities_open'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['open'], $b['overdue'], $a['name']] <=> [$a['open'], $a['overdue'], $b['name']]);

        return ['rows' => array_slice($rows, 0, (int) config('tickets.dashboard.max_employee_rows')), 'total_people' => count($rows)];
    }

    /**
     * @param  Builder<Ticket>|Builder<Activity>  $builder
     */
    private function assignmentsOverOpen(Builder $builder, string $type, DashboardFilters $filters): QueryBuilder
    {
        $openIds = (clone $builder)->open()->select("{$type}.id")->toBase();
        $morph = $type === 'tickets' ? (new Ticket)->getMorphClass() : (new Activity)->getMorphClass();

        return Assignment::query()
            ->join($type, fn (JoinClause $join) => $join
                ->on("{$type}.id", '=', 'assignments.assignable_id')
                ->where('assignments.assignable_type', '=', $morph))
            ->whereIn("{$type}.id", $openIds)
            ->when($filters->userId, fn (Builder $q, int $id) => $q->where('assignments.user_id', $id))
            ->toBase()
            ->select('assignments.user_id')
            ->selectRaw(
                "count(*) as open_count,
                 coalesce(sum(case when {$type}.status = ? then 1 else 0 end), 0) as review_count,
                 coalesce(sum(case when {$type}.due_date is not null and {$type}.due_date < ? then 1 else 0 end), 0) as overdue_count",
                [TicketStatus::InReview->value, LocalTime::today()],
            )
            ->groupBy('assignments.user_id');
    }

    // --- Tiempo de cierre ---------------------------------------------------

    /**
     * Promedio de cierre de lo completado en el periodo, por categoría y por equipo. Una consulta por tipo
     * (agrupa por equipo y categoría a la vez y suma en PHP; el promedio de la unión sale de suma/cuenta).
     *
     * @param  array<string, Builder<Ticket>|Builder<Activity>>  $builders
     * @return array{closed: int, average_seconds: ?int, by_category: list<array<string, mixed>>, by_team: list<array<string, mixed>>}
     */
    private function closing(array $builders, DashboardFilters $filters): array
    {
        $byCategory = [];
        $byTeam = [];

        foreach ($builders as $type => $builder) {
            $seconds = SqlExpressions::secondsBetween("{$type}.created_at", "{$type}.completed_at");
            $rows = $this->completedInPeriod($builder, $type, $filters)
                ->select(["{$type}.team_id as team_id", "{$type}.category_id as category_id"])
                ->selectRaw("count(*) as closed, coalesce(sum({$seconds}), 0) as seconds")
                ->groupBy("{$type}.team_id", "{$type}.category_id")
                ->get();

            foreach ($rows as $row) {
                $this->addClosed($byTeam, $row->team_id, (int) $row->closed, (int) $row->seconds);
                $this->addClosed($byCategory, $row->category_id, (int) $row->closed, (int) $row->seconds);
            }
        }

        $closed = array_sum(array_column($byTeam, 'closed'));
        $seconds = array_sum(array_column($byTeam, 'seconds'));

        return [
            'closed' => $closed,
            'average_seconds' => $closed === 0 ? null : (int) round($seconds / $closed),
            'by_category' => $this->closingRows($byCategory, Category::class),
            'by_team' => $this->closingRows($byTeam, Team::class),
        ];
    }

    /**
     * @param  array<int|string, array{closed: int, seconds: int}>  $bucket
     */
    private function addClosed(array &$bucket, mixed $id, int $closed, int $seconds): void
    {
        $key = $id === null ? 'none' : (int) $id;
        $bucket[$key] ??= ['closed' => 0, 'seconds' => 0];
        $bucket[$key]['closed'] += $closed;
        $bucket[$key]['seconds'] += $seconds;
    }

    /**
     * @param  array<int|string, array{closed: int, seconds: int}>  $bucket
     * @param  class-string<Category|Team>  $model
     * @return list<array{id: ?int, name: ?string, closed: int, average_seconds: int}>
     */
    private function closingRows(array $bucket, string $model): array
    {
        $ids = array_values(array_filter(array_keys($bucket), 'is_int'));
        $names = $model::query()->whereIn('id', $ids)->pluck('name', 'id');
        $rows = [];

        foreach ($bucket as $id => $sums) {
            $rows[] = [
                'id' => $id === 'none' ? null : $id,
                'name' => $id === 'none' ? null : (string) ($names[$id] ?? '#'.$id),
                'closed' => $sums['closed'],
                'average_seconds' => (int) round($sums['seconds'] / max($sums['closed'], 1)),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['closed'], (string) $a['name']] <=> [$a['closed'], (string) $b['name']]);

        return $rows;
    }

    // --- Utilidades ---------------------------------------------------------

    /**
     * @param  Builder<Ticket>|Builder<Activity>  $builder
     */
    private function createdInPeriod(Builder $builder, string $type, DashboardFilters $filters): QueryBuilder
    {
        return (clone $builder)->toBase()
            ->where("{$type}.created_at", '>=', $filters->startsAt())
            ->where("{$type}.created_at", '<', $filters->endsBefore());
    }

    /**
     * @param  Builder<Ticket>|Builder<Activity>  $builder
     */
    private function completedInPeriod(Builder $builder, string $type, DashboardFilters $filters): QueryBuilder
    {
        return (clone $builder)->toBase()
            ->where("{$type}.status", TicketStatus::Completed->value)
            ->where("{$type}.completed_at", '>=', $filters->startsAt())
            ->where("{$type}.completed_at", '<', $filters->endsBefore());
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{tickets: int, activities: int}>
     */
    private function zeroed(array $keys): array
    {
        return array_fill_keys($keys, ['tickets' => 0, 'activities' => 0]);
    }

    /**
     * @param  array<string, array{tickets: int, activities: int}>  $counts
     * @return list<array<string, int|string>>
     */
    private function flatten(array $counts): array
    {
        $rows = [];

        foreach ($counts as $key => $byType) {
            $rows[] = ['key' => $key, ...$this->withTotal($byType)];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $byType
     * @return array<string, int>
     */
    private function withTotal(array $byType): array
    {
        return [...$byType, 'total' => array_sum($byType)];
    }

    /**
     * Enums castados o cadenas crudas de la BD, siempre como cadena.
     */
    private function raw(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
