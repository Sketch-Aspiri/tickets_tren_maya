<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\DashboardMetricsService;
use App\Support\Dashboard\DashboardFilters;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsDashboardScenario;
use Tests\DatabaseTestCase;

/**
 * Cada agregado del panel con datos conocidos (ver BuildsDashboardScenario para el detalle de cada registro).
 */
class DashboardMetricsServiceTest extends DatabaseTestCase
{
    use BuildsDashboardScenario;

    private DashboardMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->freezeToday();
        $this->buildDashboardData();
        $this->metrics = app(DashboardMetricsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function report(object $user, array $filters = []): array
    {
        return $this->metrics->report($user, DashboardFilters::fromValidated($filters));
    }

    public function test_default_period_is_the_last_30_days_in_business_time(): void
    {
        $filters = DashboardFilters::fromValidated([]);

        $this->assertSame('2026-08-26', $filters->from);
        $this->assertSame('2026-09-24', $filters->to);
        $this->assertSame(30, $filters->days());
        $this->assertSame('2026-08-26 05:00:00', $filters->startsAt());
        $this->assertSame('2026-09-25 05:00:00', $filters->endsBefore());
    }

    public function test_cards_count_tickets_and_activities_with_a_breakdown_by_type(): void
    {
        $cards = $this->report($this->jefe)['cards'];

        $this->assertSame(['tickets' => 5, 'activities' => 2, 'total' => 7], $cards['open']);
        $this->assertSame(['tickets' => 1, 'activities' => 0, 'total' => 1], $cards['in_review']);
        $this->assertSame(['tickets' => 2, 'activities' => 1, 'total' => 3], $cards['overdue']);
        $this->assertSame(['tickets' => 2, 'activities' => 1, 'total' => 3], $cards['completed']);
    }

    public function test_soft_deleted_records_and_recurrence_templates_are_never_counted(): void
    {
        // T9 (eliminado) y A3 (plantilla) estan abiertos y con datos dentro del periodo: si contaran, open subiria.
        $report = $this->report($this->jefe);

        $this->assertSame(5, $report['cards']['open']['tickets']);
        $this->assertSame(2, $report['cards']['open']['activities']);
        $this->assertSame(10, $report['created_distribution']['total']);
    }

    public function test_open_and_overdue_are_a_snapshot_of_today_regardless_of_the_period(): void
    {
        // Un periodo lejano que no contiene nada: lo abierto y lo vencido de hoy no cambia; lo completado si.
        $report = $this->report($this->jefe, ['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertSame(7, $report['cards']['open']['total']);
        $this->assertSame(3, $report['cards']['overdue']['total']);
        $this->assertSame(0, $report['cards']['completed']['total']);
        $this->assertSame(0, $report['created_distribution']['total']);
    }

    public function test_completed_uses_the_completion_date_and_period_boundaries_are_in_business_time(): void
    {
        // T8 se completo el 2026-08-01: solo aparece en un periodo que incluya esa fecha.
        $this->assertSame(1, $this->report($this->jefe, ['from' => '2026-08-01', 'to' => '2026-08-01'])['cards']['completed']['tickets']);

        // T5 se completo a las 12:00 UTC del 09-20 (07:00 Cancun): dia local 09-20, no el anterior.
        $this->assertSame(1, $this->report($this->jefe, ['from' => '2026-09-20', 'to' => '2026-09-20'])['cards']['completed']['tickets']);
        $this->assertSame(0, $this->report($this->jefe, ['from' => '2026-09-19', 'to' => '2026-09-19'])['cards']['completed']['tickets']);
    }

    public function test_created_distribution_by_status_and_priority(): void
    {
        $distribution = $this->report($this->jefe)['created_distribution'];

        $status = array_column($distribution['by_status'], null, 'key');
        $this->assertSame(['tickets' => 2, 'activities' => 1, 'total' => 3], array_diff_key($status['pending'], ['key' => 1]));
        $this->assertSame(2, $status['in_progress']['total']);
        $this->assertSame(1, $status['in_review']['total']);
        $this->assertSame(3, $status['completed']['total']);
        $this->assertSame(1, $status['cancelled']['total']);

        $priority = array_column($distribution['by_priority'], null, 'key');
        $this->assertSame(1, $priority['low']['total']);
        $this->assertSame(6, $priority['medium']['total']);
        $this->assertSame(2, $priority['high']['total']);
        $this->assertSame(1, $priority['urgent']['total']);
        $this->assertSame(10, array_sum(array_column($distribution['by_priority'], 'total')));
    }

    public function test_distribution_lists_every_status_and_priority_even_when_empty(): void
    {
        $distribution = $this->report($this->jefe, ['from' => '2026-01-01', 'to' => '2026-01-31'])['created_distribution'];

        $this->assertSame(['pending', 'in_progress', 'in_review', 'completed', 'cancelled'], array_column($distribution['by_status'], 'key'));
        $this->assertSame(['low', 'medium', 'high', 'urgent'], array_column($distribution['by_priority'], 'key'));
        $this->assertSame(0, $distribution['total']);
    }

    public function test_trend_groups_created_and_completed_by_local_day(): void
    {
        $trend = $this->report($this->jefe)['trend'];
        $points = array_column($trend['points'], null, 'label');

        $this->assertSame('day', $trend['granularity']);
        $this->assertCount(30, $trend['points']);
        $this->assertSame('2026-08-26', $trend['points'][0]['label']);
        $this->assertSame('2026-09-24', $trend['points'][29]['label']);

        // T5 se creo a las 00:00 UTC del 09-20 = 19:00 del 09-19 en Cancun; A2 a las 00:00 UTC del 09-22 = 09-21 local.
        $this->assertSame(1, $points['2026-09-19']['created']);
        $this->assertSame(1, $points['2026-09-20']['created']);
        $this->assertSame(2, $points['2026-09-21']['created']);
        $this->assertSame(2, $points['2026-09-22']['created']);
        $this->assertSame(2, $points['2026-09-23']['created']);
        $this->assertSame(1, $points['2026-09-24']['created']);
        $this->assertSame(1, $points['2026-09-10']['created']);
        $this->assertSame(10, array_sum(array_column($trend['points'], 'created')));

        $this->assertSame(1, $points['2026-09-20']['completed']);
        $this->assertSame(1, $points['2026-09-22']['completed']);
        $this->assertSame(1, $points['2026-09-23']['completed']);
        $this->assertSame(3, array_sum(array_column($trend['points'], 'completed')));
    }

    public function test_long_periods_are_grouped_by_iso_week(): void
    {
        $trend = $this->report($this->jefe, ['from' => '2026-06-01', 'to' => '2026-09-24'])['trend'];

        $this->assertSame('week', $trend['granularity']);
        $this->assertSame('2026-06-01', $trend['points'][0]['label'], 'el 1 de junio de 2026 es lunes');
        foreach ($trend['points'] as $point) {
            $this->assertSame(1, Carbon::parse($point['label'])->dayOfWeekIso, 'cada punto semanal es un lunes');
        }
        // 9 tickets (T9 eliminado no cuenta) + A1, A2 y A4 (la plantilla A3 no cuenta) = 12.
        $this->assertSame(12, array_sum(array_column($trend['points'], 'created')));
    }

    public function test_workload_counts_open_assignments_per_person(): void
    {
        $rows = array_column($this->report($this->jefe)['workload']['rows'], null, 'user_id');

        $this->assertSame([$this->empA1->id, $this->empA2->id, $this->empB1->id], array_keys($rows));
        $this->assertSame(['tickets_open' => 2, 'activities_open' => 1, 'in_review' => 0, 'overdue' => 2, 'open' => 3], array_intersect_key($rows[$this->empA1->id], array_flip(['tickets_open', 'activities_open', 'in_review', 'overdue', 'open'])));
        $this->assertSame(['tickets_open' => 2, 'activities_open' => 0, 'in_review' => 1, 'overdue' => 0, 'open' => 2], array_intersect_key($rows[$this->empA2->id], array_flip(['tickets_open', 'activities_open', 'in_review', 'overdue', 'open'])));
        $this->assertSame(1, $rows[$this->empB1->id]['open']);
        $this->assertSame(1, $rows[$this->empB1->id]['overdue']);
        $this->assertSame('Emp A1', $rows[$this->empA1->id]['name']);
        $this->assertSame(3, $this->report($this->jefe)['workload']['total_people']);
    }

    public function test_workload_ignores_final_deleted_and_template_items(): void
    {
        // T4 (completado, empA1) y T7/T8 no suman; el completado no aparece como carga.
        $rows = array_column($this->report($this->jefe)['workload']['rows'], null, 'user_id');

        $this->assertSame(3, $rows[$this->empA1->id]['open']);
    }

    public function test_workload_is_limited_to_the_configured_number_of_rows(): void
    {
        config(['tickets.dashboard.max_employee_rows' => 2]);

        $workload = $this->report($this->jefe)['workload'];

        $this->assertCount(2, $workload['rows']);
        $this->assertSame(3, $workload['total_people']);
    }

    public function test_average_closing_time_by_category_and_team_and_overall(): void
    {
        $closing = $this->report($this->jefe)['closing'];

        // T4 = 48 h, T5 = 12 h, A2 = 6 h  =>  237600 s / 3 = 79200 s.
        $this->assertSame(3, $closing['closed']);
        $this->assertSame(79200, $closing['average_seconds']);

        $byCategory = array_column($closing['by_category'], null, 'name');
        $this->assertSame(['id' => $this->categoryC1->id, 'name' => 'Soporte', 'closed' => 2, 'average_seconds' => 97200], $byCategory['Soporte']);
        $this->assertSame(['id' => null, 'name' => null, 'closed' => 1, 'average_seconds' => 43200], $byCategory['']);

        $byTeam = array_column($closing['by_team'], null, 'name');
        $this->assertSame(97200, $byTeam[$this->teamA->name]['average_seconds']);
        $this->assertSame(2, $byTeam[$this->teamA->name]['closed']);
        $this->assertSame(43200, $byTeam[$this->teamB->name]['average_seconds']);
    }

    public function test_average_closing_time_is_null_when_nothing_closed_in_the_period(): void
    {
        $closing = $this->report($this->jefe, ['from' => '2026-01-01', 'to' => '2026-01-31'])['closing'];

        $this->assertSame(0, $closing['closed']);
        $this->assertNull($closing['average_seconds']);
        $this->assertSame([], $closing['by_category']);
        $this->assertSame([], $closing['by_team']);
    }

    public function test_filters_narrow_by_team_category_and_person(): void
    {
        $byTeam = $this->report($this->jefe, ['team_id' => $this->teamB->id]);
        $this->assertSame(['tickets' => 1, 'activities' => 1, 'total' => 2], $byTeam['cards']['open']);
        $this->assertSame($this->teamB->id, $byTeam['scope']['team_id']);

        $byCategory = $this->report($this->jefe, ['category_id' => $this->categoryC1->id]);
        $this->assertSame(2, $byCategory['cards']['completed']['total']);
        $this->assertSame(0, $byCategory['cards']['open']['total']);

        $byPerson = $this->report($this->jefe, ['user_id' => $this->empA2->id]);
        $this->assertSame(2, $byPerson['cards']['open']['tickets']);
        $this->assertSame(1, $byPerson['cards']['in_review']['total']);
        $this->assertSame([$this->empA2->id], array_column($byPerson['workload']['rows'], 'user_id'));
    }

    public function test_metrics_with_no_data_are_all_zero(): void
    {
        $newTeam = Team::factory()->create();

        $report = $this->report($this->jefe, ['team_id' => $newTeam->id]);

        $this->assertSame(0, $report['cards']['open']['total']);
        $this->assertSame([], $report['workload']['rows']);
        $this->assertNull($report['closing']['average_seconds']);
        $this->assertSame(0, array_sum(array_column($report['trend']['points'], 'created')));
    }

    public function test_report_is_cached_per_user_scope_and_filters(): void
    {
        config(['tickets.dashboard.cache_ttl' => 60]);
        Cache::flush();

        $first = $this->report($this->coordA);
        Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create(['created_at' => '2026-09-24 12:00:00']);

        // Mismo usuario y filtros: se sirve del cache (no ve el ticket nuevo).
        $this->assertSame($first['cards']['open']['tickets'], $this->report($this->coordA)['cards']['open']['tickets']);
        // Otros filtros: otra llave.
        $this->assertSame($first['cards']['open']['tickets'] + 1, $this->report($this->coordA, ['category_id' => null, 'from' => '2026-09-01'])['cards']['open']['tickets']);
        // Otro usuario/alcance nunca recibe el resultado de este.
        $this->assertNotSame($first['cards']['open']['total'], $this->report($this->coordB)['cards']['open']['total']);
        $this->assertNotSame(
            $this->metrics->cacheKey($this->coordA, DashboardFilters::fromValidated([])),
            $this->metrics->cacheKey($this->coordB, DashboardFilters::fromValidated([])),
        );
    }

    public function test_cache_key_changes_when_the_role_or_team_of_the_same_user_changes(): void
    {
        $filters = DashboardFilters::fromValidated([]);
        $before = $this->metrics->cacheKey($this->coordA, $filters);

        $this->coordA->teams()->attach($this->teamB->id);

        $this->assertNotSame($before, $this->metrics->cacheKey($this->coordA, $filters));

        $this->coordA->teams()->detach($this->teamA->id);

        $this->assertNotSame($before, $this->metrics->cacheKey($this->coordA, $filters));
    }

    public function test_a_coordinator_of_two_teams_gets_the_combined_scope_and_can_narrow_to_one(): void
    {
        $this->coordA->teams()->attach($this->teamB->id);

        $both = $this->report($this->coordA);
        $onlyB = $this->metrics->report($this->coordA, DashboardFilters::fromValidated(['team_id' => $this->teamB->id]));
        $jefeTotal = $this->report($this->jefe)['cards']['open']['total'];

        $this->assertSame($jefeTotal, $both['cards']['open']['total'], 'con A y B ve lo mismo que el jefe (solo hay dos equipos)');
        $this->assertSame([$this->teamA->name, $this->teamB->name], $both['scope']['team_names']);
        $this->assertNull($both['scope']['team_id']);
        $this->assertSame($this->teamB->id, $onlyB['scope']['team_id']);
        $this->assertLessThan($both['cards']['open']['total'], $onlyB['cards']['open']['total']);
    }

    public function test_category_names_come_from_the_database_only_for_used_categories(): void
    {
        Category::factory()->create(['name' => 'Sin uso']);

        $names = array_column($this->report($this->jefe)['closing']['by_category'], 'name');

        $this->assertNotContains('Sin uso', $names);
    }
}
