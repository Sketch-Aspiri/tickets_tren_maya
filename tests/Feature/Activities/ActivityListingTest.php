<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

class ActivityListingTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function listedIds(User $actor, array $query = []): array
    {
        $response = $this->signIn($actor)->get('/activities?'.http_build_query($query))->assertOk();

        return collect($response->viewData('activities')->items())->pluck('id')->all();
    }

    // --- Alcance -------------------------------------------------------------------------------------------------------------------

    public function test_the_list_is_scoped_by_role(): void
    {
        $a = $this->makeActivity($this->teamA);
        $b = $this->makeActivity($this->teamB);
        $assignedAcross = $this->makeActivity($this->teamB, [], $this->coordA);
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => '2030-01-01']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $assignedAcross->id, $template->id], $this->listedIds($this->jefe));
        $this->assertEqualsCanonicalizing([$a->id, $assignedAcross->id, $template->id], $this->listedIds($this->coordA));
        $this->assertEqualsCanonicalizing([$b->id, $assignedAcross->id], $this->listedIds($this->coordB), 'las del equipo B, aunque estén asignadas a otro coordinador');
    }

    public function test_employees_have_no_activity_list_but_reach_their_work_from_pending(): void
    {
        $this->signIn($this->empA1)->get('/activities')->assertForbidden();
    }

    public function test_filters_never_widen_the_scope(): void
    {
        $this->makeActivity($this->teamB, ['title' => 'Ajena']);
        $own = $this->makeActivity($this->teamA, ['title' => 'Propia']);

        $this->assertSame([], $this->listedIds($this->coordA, ['team_id' => $this->teamB->id]));
        $this->assertSame([], $this->listedIds($this->coordA, ['q' => 'Ajena']));
        $this->assertSame([$own->id], $this->listedIds($this->coordA, ['q' => '']));
    }

    // --- Filtros ----------------------------------------------------------------------------------------------------------------------

    public function test_filters_by_status_priority_category_and_responsible(): void
    {
        $category = Category::factory()->create();
        $wanted = $this->makeActivity($this->teamA, ['status' => TicketStatus::InProgress, 'priority' => Priority::Urgent, 'category_id' => $category->id], $this->empA1);
        $this->assignTo($wanted, $this->empA2, AssignmentRole::Colaborador);
        $other = $this->makeActivity($this->teamA, ['status' => TicketStatus::Pending, 'priority' => Priority::Low], $this->empA2);

        $this->assertSame([$wanted->id], $this->listedIds($this->coordA, ['status' => 'in_progress']));
        $this->assertSame([$wanted->id], $this->listedIds($this->coordA, ['priority' => 'urgent']));
        $this->assertSame([$wanted->id], $this->listedIds($this->coordA, ['category_id' => $category->id]));
        $this->assertSame([$wanted->id], $this->listedIds($this->coordA, ['responsible_id' => $this->empA1->id]));
        $this->assertSame([$other->id], $this->listedIds($this->coordA, ['responsible_id' => $this->empA2->id]), 'solo cuenta el responsable, no el colaborador');
    }

    public function test_filter_by_team_for_the_jefe(): void
    {
        $a = $this->makeActivity($this->teamA);
        $b = $this->makeActivity($this->teamB);

        $this->assertSame([$b->id], $this->listedIds($this->jefe, ['team_id' => $this->teamB->id]));
        $this->assertContains($a->id, $this->listedIds($this->jefe));
    }

    public function test_filter_overdue_uses_business_time_and_open_states(): void
    {
        $late = $this->makeActivity($this->teamA, ['due_date' => now()->subDays(3)->toDateString()]);
        $this->makeActivity($this->teamA, ['due_date' => now()->addDays(3)->toDateString()]);
        $this->makeActivity($this->teamA, ['due_date' => now()->subDays(3)->toDateString(), 'status' => TicketStatus::Completed]);

        $this->assertSame([$late->id], $this->listedIds($this->coordA, ['overdue' => 1]));
    }

    public function test_filter_by_kind_single_template_or_instance(): void
    {
        $single = $this->makeActivity($this->teamA);
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => '2030-01-01']);
        $instance = $this->makeActivity($this->teamA, ['parent_activity_id' => $template->id, 'occurrence_date' => '2030-01-02']);

        $this->assertSame([$single->id], $this->listedIds($this->coordA, ['kind' => 'single']));
        $this->assertSame([$template->id], $this->listedIds($this->coordA, ['kind' => 'template']));
        $this->assertSame([$instance->id], $this->listedIds($this->coordA, ['kind' => 'instance']));
        $this->assertEqualsCanonicalizing([$single->id, $template->id, $instance->id], $this->listedIds($this->coordA));
    }

    public function test_search_matches_title_and_folio_and_escapes_like_wildcards(): void
    {
        $percent = $this->makeActivity($this->teamA, ['title' => 'Avance al 100% listo']);
        $underscore = $this->makeActivity($this->teamA, ['title' => 'Reporte_final']);
        $plain = $this->makeActivity($this->teamA, ['title' => 'Reporteafinal', 'folio' => 'ACT-2026-0777']);

        $this->assertSame([$percent->id], $this->listedIds($this->coordA, ['q' => '100%']));
        $this->assertSame([$underscore->id], $this->listedIds($this->coordA, ['q' => 'Reporte_final']));
        $this->assertSame([$plain->id], $this->listedIds($this->coordA, ['q' => '0777']));
        $this->assertSame([], $this->listedIds($this->coordA, ['q' => "' OR 1=1 --"]));
        $this->assertSame([$percent->id], $this->listedIds($this->coordA, ['q' => '%']), 'el % es literal, no un comodín');
    }

    public function test_sorting_by_priority_and_due_date_uses_business_order(): void
    {
        $low = $this->makeActivity($this->teamA, ['priority' => Priority::Low, 'due_date' => '2030-03-01']);
        $urgent = $this->makeActivity($this->teamA, ['priority' => Priority::Urgent, 'due_date' => '2030-01-01']);
        $noDate = $this->makeActivity($this->teamA, ['priority' => Priority::High]);

        $this->assertSame([$urgent->id, $noDate->id, $low->id], $this->listedIds($this->coordA, ['sort' => 'priority', 'direction' => 'desc']));
        $this->assertSame([$urgent->id, $low->id, $noDate->id], $this->listedIds($this->coordA, ['sort' => 'due_date', 'direction' => 'asc']));
    }

    public function test_default_order_is_by_due_date_soonest_first_with_undated_last(): void
    {
        $late = $this->makeActivity($this->teamA, ['due_date' => '2030-05-01']);
        $olderUndated = $this->makeActivity($this->teamA);
        $soon = $this->makeActivity($this->teamA, ['due_date' => '2030-01-01']);
        $newerUndated = $this->makeActivity($this->teamA);

        $this->assertSame([$soon->id, $late->id, $newerUndated->id, $olderUndated->id], $this->listedIds($this->coordA));
        $this->assertSame([$newerUndated->id, $soon->id, $olderUndated->id, $late->id], $this->listedIds($this->coordA, ['sort' => 'created_at']));
    }

    public function test_due_today_filter_lists_only_open_non_template_activities_due_today(): void
    {
        $today = LocalTime::today();
        $dueToday = $this->makeActivity($this->teamA, ['due_date' => $today]);
        $dueTodayInProgress = $this->makeActivity($this->teamA, ['due_date' => $today, 'status' => TicketStatus::InProgress]);
        $this->makeActivity($this->teamA, ['due_date' => $today, 'status' => TicketStatus::Completed]);
        $this->makeActivity($this->teamA, ['due_date' => $today, 'status' => TicketStatus::Cancelled]);
        $this->makeActivity($this->teamA, ['due_date' => CarbonImmutable::parse($today)->addDay()->toDateString()]);
        $this->makeActivity($this->teamA, ['due_date' => CarbonImmutable::parse($today)->subDay()->toDateString()]);
        $this->makeActivity($this->teamA);
        $this->makeActivity($this->teamA, ['due_date' => $today, 'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => $today]);

        $this->assertEqualsCanonicalizing([$dueToday->id, $dueTodayInProgress->id], $this->listedIds($this->coordA, ['due_today' => 1]));
    }

    public function test_due_today_filter_never_widens_the_scope_and_combines_with_other_filters(): void
    {
        $today = LocalTime::today();
        $mine = $this->makeActivity($this->teamA, ['due_date' => $today, 'priority' => Priority::High]);
        $mineLow = $this->makeActivity($this->teamA, ['due_date' => $today, 'priority' => Priority::Low]);
        $foreign = $this->makeActivity($this->teamB, ['due_date' => $today, 'priority' => Priority::High]);

        $this->assertEqualsCanonicalizing([$mine->id, $mineLow->id], $this->listedIds($this->coordA, ['due_today' => 1]));
        $this->assertEqualsCanonicalizing([$mine->id, $mineLow->id, $foreign->id], $this->listedIds($this->jefe, ['due_today' => 1]));
        $this->assertSame([$mine->id], $this->listedIds($this->coordA, ['due_today' => 1, 'priority' => 'high']));
    }

    public function test_the_due_today_button_toggles_the_filter_and_keeps_other_filters(): void
    {
        $this->signIn($this->coordA)->get('/activities?priority=high')->assertOk()
            ->assertSee(__('activities.filters.due_today'))
            ->assertSee(e(route('activities.index', ['priority' => 'high', 'due_today' => 1])), false)
            ->assertDontSee('name="due_today"', false);

        $this->signIn($this->coordA)->get('/activities?priority=high&due_today=1')->assertOk()
            ->assertSee(e(route('activities.index', ['priority' => 'high'])), false)
            ->assertSee('name="due_today"', false);
    }

    public function test_invalid_filters_are_rejected_by_the_form_request(): void
    {
        $this->signIn($this->coordA);

        foreach ([
            ['status', 'overdue'], ['priority', 'x'], ['kind', 'all'], ['sort', 'password'], ['direction', 'sideways'],
            ['category_id', 'abc'], ['team_id', 9999], ['q', str_repeat('a', 101)], ['page', 0],
        ] as [$field, $value]) {
            $this->get('/activities?'.http_build_query([$field => $value]))->assertInvalid([$field]);
        }
    }

    public function test_the_list_is_paginated_and_keeps_filters_in_the_links(): void
    {
        Activity::factory()->count(20)->forTeam($this->teamA)->priority(Priority::High)->create();

        $this->assertCount(15, $this->listedIds($this->coordA, ['priority' => 'high']));
        $this->assertCount(5, $this->listedIds($this->coordA, ['priority' => 'high', 'page' => 2]));
        $this->signIn($this->coordA)->get('/activities?priority=high')->assertSee('priority=high', false);
    }

    // --- Rendimiento ------------------------------------------------------------------------------------------------------------------

    private function queryCount(User $actor, string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signIn($actor)->get($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_index_and_pending_use_a_constant_number_of_queries(): void
    {
        $seed = function (int $howMany): void {
            foreach (range(1, $howMany) as $ignored) {
                $activity = $this->makeActivity($this->teamA, ['category_id' => Category::factory()->create()->id], $this->empA1);
                $this->assignTo($activity, $this->empA2, AssignmentRole::Colaborador);
                $this->makeSubtask($activity, 'a', true);
                $this->makeSubtask($activity, 'b');
            }
        };

        // El empleado no tiene listado de actividades: solo su vista "Mis pendientes".
        $targets = [
            ['/activities', $this->jefe], ['/activities', $this->coordA],
            ['/tickets/pending', $this->jefe], ['/tickets/pending', $this->coordA], ['/tickets/pending', $this->empA1],
        ];

        $seed(2);
        foreach ($targets as [$uri, $actor]) {
            $this->queryCount($actor, $uri); // calienta
        }
        $small = array_map(fn (array $target): int => $this->queryCount($target[1], $target[0]), $targets);

        $seed(10);
        foreach ($targets as $index => [$uri, $actor]) {
            $this->assertSame($small[$index], $this->queryCount($actor, $uri), "N+1 en {$uri} para {$actor->name}");
        }
    }

    public function test_show_page_does_not_grow_queries_with_subtasks_comments_history_and_files(): void
    {
        $activity = $this->makeActivity($this->teamA, ['status' => TicketStatus::InProgress], $this->empA1);
        $grow = function () use ($activity): void {
            $author = User::factory()->empleado()->create();
            $this->makeSubtask($activity, 'x', false, $author);
            $activity->comments()->create(['body' => 'x', 'user_id' => $author->id]);
            $activity->statusHistories()->create(['from_status' => 'pending', 'to_status' => 'in_progress', 'user_id' => $this->coordA->id]);
            $activity->attachments()->create(['user_id' => $author->id, 'original_name' => 'a.pdf', 'path' => 'activities/1/'.Str::random(20).'.pdf', 'mime' => 'application/pdf', 'size' => 10]);
            $this->assignTo($activity, User::factory()->empleado()->create(['team_id' => $this->teamA->id]), AssignmentRole::Colaborador);
        };

        foreach (range(1, 2) as $ignored) {
            $grow();
        }
        $this->queryCount($this->coordA, "/activities/{$activity->id}");
        $small = $this->queryCount($this->coordA, "/activities/{$activity->id}");

        foreach (range(1, 8) as $ignored) {
            $grow();
        }

        $this->assertSame($small, $this->queryCount($this->coordA, "/activities/{$activity->id}"));
    }
}
