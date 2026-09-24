<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\AssignmentRole;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DashboardScope;
use App\Support\Dashboard\DashboardFilters;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsDashboardScenario;
use Tests\DatabaseTestCase;

/**
 * Acceso, alcance por rol, filtros (validacion e IDOR), contenido de la vista y consultas del panel.
 */
class TrackingPanelTest extends DatabaseTestCase
{
    use BuildsDashboardScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->freezeToday();
        $this->buildDashboardData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function cards(User $actor, string $query = ''): array
    {
        return $this->signIn($actor)->get('/tracking'.$query)->assertOk()->viewData('report')['cards'];
    }

    // --- Autenticacion y acceso ----------------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/tracking')->assertRedirect('/login');
        $this->getJson('/tracking')->assertUnauthorized();
    }

    public function test_user_without_the_two_factor_challenge_cannot_reach_the_panel(): void
    {
        $this->actingAs($this->jefe)->get('/tracking')->assertRedirect(route('two-factor.challenge'));
        $this->actingAs($this->coordA)->get('/tracking')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_pending_and_inactive_accounts_cannot_reach_the_panel(): void
    {
        $pending = User::factory()->pending()->create();
        $inactive = User::factory()->empleado()->inactive()->create();

        $this->signIn($pending)->get('/tracking')->assertRedirect(route('account.status'));
        $this->signIn($inactive)->get('/tracking')->assertRedirect(route('account.status'));
    }

    public function test_jefe_and_coordinator_can_open_the_panel_and_employee_gets_403(): void
    {
        $this->signIn($this->jefe)->get('/tracking')->assertOk();
        $this->signIn($this->coordA)->get('/tracking')->assertOk();
        $this->signIn($this->empA1)->get('/tracking')->assertForbidden();
    }

    public function test_employee_is_forbidden_before_filters_are_validated(): void
    {
        // No debe aprender nada de las reglas de validacion: 403, no 422/redireccion con errores.
        $this->signIn($this->empA1)->get('/tracking?from=basura&team_id=999')->assertForbidden();
        $this->signIn($this->empA1)->getJson('/tracking?from=basura')->assertForbidden();
    }

    public function test_a_coordinator_without_the_permission_is_forbidden(): void
    {
        Role::findByName('coordinador')->revokePermissionTo('dashboard.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->signIn($this->coordA->fresh())->get('/tracking')->assertForbidden();
    }

    // --- Alcance por rol -------------------------------------------------------------------------------

    public function test_jefe_sees_everything_and_the_scope_label_is_global(): void
    {
        $response = $this->signIn($this->jefe)->get('/tracking')->assertOk();

        $this->assertSame(7, $response->viewData('report')['cards']['open']['total']);
        $response->assertSee(__('tracking.scope.global'));
    }

    public function test_coordinator_sees_only_their_team(): void
    {
        $a = $this->cards($this->coordA);
        $b = $this->cards($this->coordB);

        $this->assertSame(['tickets' => 4, 'activities' => 1, 'total' => 5], $a['open']);
        $this->assertSame(['tickets' => 1, 'activities' => 1, 'total' => 2], $b['open']);
        $this->assertSame(2, $a['completed']['total']);
        $this->assertSame(1, $b['completed']['total']);
        $this->assertSame(2, $a['overdue']['total']);
        $this->assertSame(1, $b['overdue']['total']);
    }

    public function test_coordinator_scope_label_names_the_team(): void
    {
        $this->signIn($this->coordA)->get('/tracking')->assertSee(__('tracking.scope.team', ['team' => 'Equipo A']));
    }

    public function test_work_assigned_to_a_coordinator_outside_their_team_is_not_counted(): void
    {
        // visibleTo SI incluye lo asignado explicitamente al coordinador; el panel mide el equipo y lo excluye.
        Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->assignedTo($this->coordA)->create();
        Activity::factory()->forTeam($this->teamB)->createdBy($this->coordB)->assignedTo($this->coordA)->create();

        $this->assertTrue(Ticket::query()->visibleTo($this->coordA)->where('team_id', $this->teamB->id)->exists(), 'precondicion: visibleTo lo incluye');
        $this->assertSame(5, $this->cards($this->coordA)['open']['total']);
        $this->assertSame(9, $this->cards($this->jefe)['open']['total']);
    }

    public function test_workload_table_for_a_coordinator_lists_only_assignees_of_their_team_items(): void
    {
        $rows = $this->signIn($this->coordA)->get('/tracking')->viewData('report')['workload']['rows'];

        $this->assertEqualsCanonicalizing([$this->empA1->id, $this->empA2->id], array_column($rows, 'user_id'));
    }

    // --- Filtros: IDOR y validacion --------------------------------------------------------------------

    public function test_coordinator_cannot_widen_the_scope_with_another_team_id(): void
    {
        $this->signIn($this->coordA)->get('/tracking?team_id='.$this->teamB->id)->assertSessionHasErrors('team_id');
    }

    public function test_coordinator_may_send_their_own_team_id(): void
    {
        $this->signIn($this->coordA)->get('/tracking?team_id='.$this->teamA->id)->assertOk()->assertSessionHasNoErrors();
    }

    public function test_even_if_validation_were_bypassed_the_scope_ignores_a_foreign_team_filter(): void
    {
        $scope = app(DashboardScope::class);
        $filters = new DashboardFilters('2026-09-01', '2026-09-24', teamId: $this->teamB->id);

        $ids = $scope->tickets($this->coordA, $filters)->pluck('tickets.id')->all();

        $this->assertNotEmpty($ids);
        $this->assertEmpty(Ticket::query()->whereIn('id', $ids)->where('team_id', $this->teamB->id)->pluck('id')->all());
        $this->assertSame([], $scope->tickets($this->empA1, $filters)->pluck('tickets.id')->all(), 'el empleado no tiene alcance alguno');
    }

    public function test_coordinator_cannot_filter_by_a_person_from_another_team(): void
    {
        $this->signIn($this->coordA)->get('/tracking?user_id='.$this->empB1->id)->assertSessionHasErrors('user_id');
        $this->signIn($this->coordA)->get('/tracking?user_id='.$this->empA1->id)->assertOk()->assertSessionHasNoErrors();
    }

    public function test_jefe_can_filter_by_team_person_and_category(): void
    {
        $this->assertSame(2, $this->cards($this->jefe, '?team_id='.$this->teamB->id)['open']['total']);
        $this->assertSame(3, $this->cards($this->jefe, '?user_id='.$this->empA1->id)['open']['total']);
        $this->assertSame(2, $this->cards($this->jefe, '?category_id='.$this->categoryC1->id)['completed']['total']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'fecha con formato invalido' => ['from=24/09/2026', 'from'],
            'fecha inexistente' => ['to=2026-02-31', 'to'],
            'fecha final futura' => ['to=2026-09-25', 'to'],
            'inicio posterior al fin' => ['from=2026-09-20&to=2026-09-10', 'from'],
            'rango mayor al tope' => ['from=2025-01-01&to=2026-09-24', 'from'],
            'equipo inexistente' => ['team_id=999999', 'team_id'],
            'persona inexistente' => ['user_id=999999', 'user_id'],
            'categoria inexistente' => ['category_id=999999', 'category_id'],
            'equipo no numerico' => ['team_id=abc', 'team_id'],
            'arreglo en fecha' => ['from[]=2026-09-01', 'from'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $query, string $field): void
    {
        $this->signIn($this->jefe)->get('/tracking?'.$query)->assertSessionHasErrors($field);
    }

    public function test_the_maximum_range_is_accepted_and_one_day_more_is_not(): void
    {
        $this->signIn($this->jefe)->get('/tracking?from=2025-09-24&to=2026-09-24')->assertOk()->assertSessionHasNoErrors();
        $this->signIn($this->jefe)->get('/tracking?from=2025-09-23&to=2026-09-24')->assertSessionHasErrors('from');
    }

    public function test_default_filters_are_shown_in_the_form(): void
    {
        $this->signIn($this->jefe)->get('/tracking')->assertSee('name="from"', false)->assertSee('value="2026-08-26"', false)->assertSee('value="2026-09-24"', false);
    }

    public function test_a_single_day_period_is_valid(): void
    {
        $this->signIn($this->jefe)->get('/tracking?from=2026-09-20&to=2026-09-20')->assertOk()->assertSessionHasNoErrors();
    }

    // --- Selectores -------------------------------------------------------------------------------------

    public function test_filter_selectors_depend_on_the_role(): void
    {
        $jefe = $this->signIn($this->jefe)->get('/tracking')->assertOk();
        $this->assertCount(2, $jefe->viewData('teams'));
        $this->assertTrue($jefe->viewData('employees')->contains('id', $this->empB1->id));
        $jefe->assertSee('name="team_id"', false);

        $coord = $this->signIn($this->coordA)->get('/tracking')->assertOk();
        $this->assertCount(0, $coord->viewData('teams'));
        $this->assertEqualsCanonicalizing([$this->coordA->id, $this->empA1->id, $this->empA2->id], $coord->viewData('employees')->pluck('id')->all());
        $coord->assertDontSee('name="team_id"', false)->assertDontSee('Emp B1');
    }

    // --- Contenido y seguridad de la vista -------------------------------------------------------------

    public function test_the_page_renders_cards_tables_and_accessible_chart_alternatives(): void
    {
        $html = $this->signIn($this->jefe)->get('/tracking')->assertOk()->getContent();

        foreach (['tracking.cards.open', 'tracking.cards.in_review', 'tracking.cards.overdue', 'tracking.cards.completed', 'tracking.sections.workload', 'tracking.sections.closing'] as $key) {
            $this->assertStringContainsString(e(__($key)), $html, $key);
        }

        $this->assertSame(3, substr_count($html, '<canvas'), 'tendencia, estado y prioridad');
        $this->assertSame(3, substr_count($html, 'role="img"'));
        $this->assertSame(3, substr_count($html, '<details'), 'cada grafica trae su tabla equivalente');
        $this->assertSame(3, substr_count($html, e(__('tracking.charts.show_data'))));
    }

    public function test_chart_data_travels_as_plain_json_in_a_data_attribute(): void
    {
        $response = $this->signIn($this->jefe)->get('/tracking')->assertOk();
        $html = $response->getContent();

        preg_match_all('/<canvas data-chart="([^"]+)"/', $html, $matches);
        $this->assertCount(3, $matches[1]);

        foreach ($matches[1] as $encoded) {
            $config = json_decode(html_entity_decode($encoded), true, flags: JSON_THROW_ON_ERROR);
            $this->assertContains($config['type'], ['line', 'bar', 'doughnut']);
            $this->assertSame(count($config['labels']), count($config['datasets'][0]['data']));
        }

        $charts = $response->viewData('charts');
        $this->assertSame([__('enums.ticket_status.pending'), __('enums.ticket_status.in_progress'), __('enums.ticket_status.in_review'), __('enums.ticket_status.completed'), __('enums.ticket_status.cancelled')], $charts['status']['labels']);
        $this->assertSame([3, 2, 1, 3, 1], $charts['status']['datasets'][0]['data']);
        $this->assertSame(30, count($charts['trend']['labels']));
        $this->assertSame('bar', $charts['priority']['type']);
    }

    public function test_user_controlled_names_are_escaped_everywhere(): void
    {
        $payload = '<script>alert("x")</script>';
        $this->empA1->forceFill(['name' => $payload])->save();
        $this->categoryC1->forceFill(['name' => $payload.'C'])->save();
        $this->teamA->forceFill(['name' => $payload.'T'])->save();

        $html = $this->signIn($this->jefe)->get('/tracking')->assertOk()->getContent();

        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString(e($payload), $html);
    }

    public function test_page_has_no_inline_scripts_no_inline_handlers_and_no_unsafe_eval(): void
    {
        $response = $this->signIn($this->jefe)->get('/tracking')->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, 'sin <script> en linea');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'sin manejadores on*');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('cdn', strtolower($html), 'sin CDN externos');
    }

    public function test_alpine_attributes_on_the_page_are_plain_identifiers(): void
    {
        $html = $this->signIn($this->jefe)->get('/tracking')->assertOk()->getContent();

        preg_match_all('/\s(?:x-data|x-on:[a-z.-]+|x-bind:[a-z-]+|x-show|x-text)="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $expression) {
            $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_.]*$/', $expression);
        }
    }

    public function test_source_views_do_not_use_unescaped_output(): void
    {
        $offenders = [];

        foreach (['tracking', 'audit', 'components'] as $dir) {
            foreach (glob(resource_path("views/{$dir}/*.blade.php")) ?: [] as $file) {
                if (str_contains((string) file_get_contents($file), '{!!')) {
                    $offenders[] = basename($file);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_chart_module_is_bundled_locally_and_not_loaded_from_a_cdn(): void
    {
        $js = (string) file_get_contents(resource_path('js/charts.js'));
        $app = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("from 'chart.js'", $js);
        $this->assertStringNotContainsString('http', $js);
        $this->assertStringContainsString("import('./charts.js')", $app);
        $this->assertStringNotContainsString('eval(', $js);
        $this->assertStringNotContainsString('new Function', $js);
    }

    // --- Navegacion --------------------------------------------------------------------------------------

    public function test_sidebar_shows_the_panel_only_to_jefe_and_coordinator(): void
    {
        $this->signIn($this->jefe)->get('/dashboard')->assertSee(route('tracking.index'), false);
        $this->signIn($this->coordA)->get('/dashboard')->assertSee(route('tracking.index'), false);
        $this->signIn($this->empA1)->get('/dashboard')->assertDontSee(route('tracking.index'), false);
    }

    public function test_employee_still_lands_on_a_valid_home(): void
    {
        $this->signIn($this->empA1)->get('/dashboard')->assertOk()->assertSee(route('tickets.pending'), false);
    }

    // --- Rendimiento y limites -------------------------------------------------------------------------

    public function test_query_count_does_not_grow_with_the_amount_of_data(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->signIn($this->jefe)->get('/tracking')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count(); // calienta cache de permisos/roles
        $before = $count();

        $employees = User::factory()->empleado()->count(15)->create(['team_id' => $this->teamA->id]);
        $categories = Category::factory()->count(6)->create();
        foreach ($employees as $index => $employee) {
            Ticket::factory()->forTeam($this->teamA)->createdBy($employee)->assignedTo($employee)->completed()
                ->create(['category_id' => $categories[$index % 6]->id, 'created_at' => '2026-09-20 12:00:00', 'completed_at' => '2026-09-22 12:00:00']);
            Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->assignedTo($employee, AssignmentRole::Colaborador)
                ->create(['created_at' => '2026-09-21 12:00:00']);
        }
        Team::factory()->count(3)->create();

        $this->assertSame($before, $count());
        $this->assertLessThanOrEqual(30, $before, 'un numero acotado de consultas agregadas');
    }

    public function test_the_panel_is_rate_limited_per_user(): void
    {
        RateLimiter::clear('dashboard|'.$this->jefe->id);
        $limit = (int) config('tickets.rate_limits.dashboard_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->signIn($this->jefe)->get('/tracking')->assertOk();
        }

        $this->signIn($this->jefe)->get('/tracking')->assertStatus(429);
        // Otro usuario no comparte el contador.
        $this->signIn($this->coordA)->get('/tracking')->assertOk();
    }

    public function test_the_panel_is_read_only(): void
    {
        $this->signIn($this->jefe)->post('/tracking')->assertStatus(405);
        $this->signIn($this->jefe)->delete('/tracking')->assertStatus(405);
    }
}
