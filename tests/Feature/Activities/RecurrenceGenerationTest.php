<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\User;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Generación de instancias a partir de plantillas (RecurrenceService + `activities:generate-recurring`).
 * Fecha "de hoy" siempre en hora de Cancún (UTC-5, sin horario de verano).
 */
class RecurrenceGenerationTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        // Jueves 24/09/2026, 10:00 en Cancún.
        Carbon::setTestNow('2026-09-24 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $attributes
     */
    private function template(array $rule, string $start = '2026-09-21', array $attributes = [], ?User $responsible = null): Activity
    {
        $template = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create([
            'title' => 'Reporte semanal',
            'description' => 'Consolidar avance',
            'priority' => Priority::High,
            'recurrence_rule' => $rule + ['interval' => 1, 'days_of_week' => [], 'day_of_month' => null, 'ends_at' => null],
            'start_date' => $start,
            ...$attributes,
        ]);

        if ($responsible !== null) {
            $this->assignTo($template, $responsible);
        }

        return $template;
    }

    private function generate(): int
    {
        return app(RecurrenceService::class)->generateAll()['created'];
    }

    /**
     * @return list<string>
     */
    private function occurrences(Activity $template): array
    {
        return $template->instances()->withTrashed()->orderBy('occurrence_date')->get()->map(fn (Activity $i): string => $i->occurrence_date->toDateString())->all();
    }

    // --- Reglas de fechas ------------------------------------------------------------------------------------------------------------

    public function test_weekly_on_several_days_within_the_default_horizon(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1, 3]]);

        $this->assertSame(4, $this->generate());

        // Horizonte por defecto de 14 días desde hoy (24/09): hasta el 08/10 inclusive.
        $this->assertSame(['2026-09-28', '2026-09-30', '2026-10-05', '2026-10-07'], $this->occurrences($template));
    }

    public function test_daily_with_interval_and_the_horizon_is_inclusive(): void
    {
        $every = $this->template(['frequency' => 'daily'], '2026-09-01');
        $this->assertSame(15, $this->generate());
        $this->assertCount(15, $this->occurrences($every));
        $this->assertSame('2026-09-24', $this->occurrences($every)[0]);
        $this->assertSame('2026-10-08', $this->occurrences($every)[14]);
    }

    public function test_daily_every_three_days_keeps_its_phase(): void
    {
        $template = $this->template(['frequency' => 'daily', 'interval' => 3], '2026-09-01');

        $this->generate();

        $this->assertSame(['2026-09-25', '2026-09-28', '2026-10-01', '2026-10-04', '2026-10-07'], $this->occurrences($template));
    }

    public function test_horizon_is_configurable(): void
    {
        config(['tickets.recurrence.horizon_days' => 3]);
        $template = $this->template(['frequency' => 'daily'], '2026-09-01');

        $this->generate();

        $this->assertSame(['2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'], $this->occurrences($template));
    }

    public function test_monthly_day_31_uses_the_last_day_of_short_months_including_february(): void
    {
        Carbon::setTestNow('2027-01-30 15:00:00');
        config(['tickets.recurrence.horizon_days' => 60]);
        $template = $this->template(['frequency' => 'monthly', 'day_of_month' => 31], '2026-11-01');

        $this->generate();

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $this->occurrences($template));
    }

    public function test_monthly_february_29_in_a_leap_year(): void
    {
        Carbon::setTestNow('2028-02-20 15:00:00');
        $template = $this->template(['frequency' => 'monthly', 'day_of_month' => 31], '2028-01-01');

        $this->generate();

        $this->assertSame(['2028-02-29'], $this->occurrences($template));
    }

    public function test_ends_at_closes_the_series(): void
    {
        $template = $this->template(['frequency' => 'daily', 'ends_at' => '2026-09-26'], '2026-09-01');

        $this->generate();

        $this->assertSame(['2026-09-24', '2026-09-25', '2026-09-26'], $this->occurrences($template));
    }

    public function test_a_series_that_already_ended_or_has_not_started_generates_nothing_before_its_start(): void
    {
        $ended = $this->template(['frequency' => 'daily', 'ends_at' => '2026-09-01'], '2026-08-01');
        $future = $this->template(['frequency' => 'daily'], '2026-10-05');

        $this->generate();

        $this->assertSame([], $this->occurrences($ended));
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'], $this->occurrences($future));
    }

    public function test_past_occurrences_are_never_back_filled(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]], '2026-06-01');

        $this->generate();

        $this->assertSame(['2026-09-28', '2026-10-05'], $this->occurrences($template));
    }

    // --- Zona horaria ---------------------------------------------------------------------------------------------------------------------

    public function test_today_is_the_business_date_not_the_utc_date(): void
    {
        // 25/09 03:00 UTC = 24/09 22:00 en Cancún: la primera ocurrencia diaria es el 24, no el 25.
        Carbon::setTestNow('2026-09-25 03:00:00');
        config(['tickets.recurrence.horizon_days' => 1]);
        $template = $this->template(['frequency' => 'daily'], '2026-09-01');

        $this->generate();
        $this->assertSame(['2026-09-24', '2026-09-25'], $this->occurrences($template));

        // 25/09 05:30 UTC = 25/09 00:30 en Cancún: ya es otro día de negocio.
        Carbon::setTestNow('2026-09-25 05:30:00');
        $this->generate();
        $this->assertSame(['2026-09-24', '2026-09-25', '2026-09-26'], $this->occurrences($template));
    }

    // --- Idempotencia y folios ---------------------------------------------------------------------------------------------------------------

    public function test_running_twice_does_not_duplicate_instances_and_folios_stay_consecutive(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1, 3]]);
        $this->assignTo($template, $this->empA1);

        $first = $this->generate();
        $second = $this->generate();

        $this->assertSame(4, $first);
        $this->assertSame(0, $second);
        $this->assertSame(4, $template->instances()->count());
        $this->assertSame(1, $template->instances()->whereDate('occurrence_date', '2026-09-28')->count());

        // La plantilla se creó por fábrica (folio AF-); las instancias toman ACT-2026-0001... sin huecos.
        $this->assertSame(
            ['ACT-2026-0001', 'ACT-2026-0002', 'ACT-2026-0003', 'ACT-2026-0004'],
            $template->instances()->orderBy('occurrence_date')->pluck('folio')->all(),
        );
    }

    public function test_the_next_run_only_adds_what_the_moving_horizon_newly_covers(): void
    {
        $template = $this->template(['frequency' => 'daily'], '2026-09-01');
        $this->generate();

        Carbon::setTestNow('2026-09-26 15:00:00');

        $this->assertSame(2, $this->generate());
        $this->assertSame(17, $template->instances()->count());
        $this->assertSame('2026-10-10', $this->occurrences($template)[16]);
    }

    public function test_a_deleted_instance_is_not_regenerated(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]]);
        $this->generate();
        $template->instances()->whereDate('occurrence_date', '2026-09-28')->firstOrFail()->delete();

        $this->assertSame(0, $this->generate());
        $this->assertSame(['2026-09-28', '2026-10-05'], $this->occurrences($template));
    }

    public function test_year_change_folios_follow_the_year_of_generation(): void
    {
        Carbon::setTestNow('2026-12-28 15:00:00');
        $template = $this->template(['frequency' => 'daily'], '2026-12-01');
        config(['tickets.recurrence.horizon_days' => 7]);
        $this->generate();

        $first = $template->instances()->orderBy('occurrence_date')->first();
        $this->assertSame('2026-12-28', $first->occurrence_date->toDateString());
        $this->assertSame('ACT-2026-0001', $first->folio);
        $this->assertSame('ACT-2026-0008', $template->instances()->orderBy('occurrence_date')->get()->last()->folio);
        $this->assertSame('2027-01-04', $template->instances()->orderBy('occurrence_date')->get()->last()->occurrence_date->toDateString());

        Carbon::setTestNow('2027-01-05 15:00:00');
        $this->generate();

        $this->assertSame('ACT-2027-0001', $template->instances()->whereDate('occurrence_date', '2027-01-05')->firstOrFail()->folio);
    }

    // --- Contenido de las instancias ------------------------------------------------------------------------------------------------

    public function test_an_instance_copies_the_template_and_starts_fresh(): void
    {
        $category = Category::factory()->create();
        $template = $this->template(
            ['frequency' => 'weekly', 'days_of_week' => [1]],
            '2026-09-21',
            ['category_id' => $category->id, 'due_date' => '2026-09-23'],
            $this->empA1,
        );
        $this->assignTo($template, $this->empA2, AssignmentRole::Colaborador);
        $this->makeSubtask($template, 'Paso 1', true);
        $this->makeSubtask($template, 'Paso 2', false, $this->empA2);

        $this->generate();

        $instance = $template->instances()->orderBy('occurrence_date')->firstOrFail();
        $this->assertSame('2026-09-28', $instance->occurrence_date->toDateString());
        $this->assertSame('2026-09-28', $instance->start_date->toDateString());
        $this->assertSame('2026-09-30', $instance->due_date->toDateString(), 'vence tantos días después como la plantilla (2)');
        $this->assertSame('Reporte semanal', $instance->title);
        $this->assertSame('Consolidar avance', $instance->description);
        $this->assertSame(Priority::High, $instance->priority);
        $this->assertSame($category->id, $instance->category_id);
        $this->assertSame($this->teamA->id, $instance->team_id);
        $this->assertSame($this->coordA->id, $instance->created_by);
        $this->assertSame(TicketStatus::Pending, $instance->status);
        $this->assertNull($instance->completed_at);
        $this->assertNull($instance->recurrence_rule);
        $this->assertFalse($instance->isTemplate());
        $this->assertTrue($instance->isInstance());
        $this->assertSame($template->id, $instance->parent_activity_id);

        $this->assertEqualsCanonicalizing(
            [[$this->empA1->id, 'responsable'], [$this->empA2->id, 'colaborador']],
            $instance->assignments->map(fn ($a): array => [$a->user_id, $a->role->value])->all(),
        );

        $subtasks = $instance->subtasks()->orderBy('id')->get();
        $this->assertSame(['Paso 1', 'Paso 2'], $subtasks->pluck('title')->all());
        $this->assertSame([false, false], $subtasks->pluck('done')->all(), 'las subtareas se reinician como no hechas');
        $this->assertSame([null, $this->empA2->id], $subtasks->pluck('assigned_to')->all());
        $this->assertSame(0, $instance->progressPercent());
        $this->assertTrue($template->subtasks()->where('title', 'Paso 1')->firstOrFail()->done, 'la plantilla no cambia');

        $history = $instance->statusHistories()->firstOrFail();
        $this->assertNull($history->from_status);
        $this->assertSame(TicketStatus::Pending, $history->to_status);
    }

    public function test_generation_without_a_due_date_leaves_instances_without_one(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]]);

        $this->generate();

        $this->assertNull($template->instances()->firstOrFail()->due_date);
    }

    public function test_inactive_assignees_are_skipped_and_logged(): void
    {
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]], '2026-09-21', [], $this->empA1);
        $this->assignTo($template, $inactive, AssignmentRole::Colaborador);

        $this->generate();

        $instance = $template->instances()->orderBy('occurrence_date')->firstOrFail();
        $this->assertSame([[$this->empA1->id, 'responsable']], $instance->assignments->map(fn ($a): array => [$a->user_id, $a->role->value])->all());

        $entry = ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $instance->id)->where('event', 'assignee_skipped')->firstOrFail();
        $this->assertSame($inactive->id, $entry->properties['user_id']);
    }

    public function test_an_inactive_responsible_leaves_the_instance_without_responsible_but_keeps_collaborators(): void
    {
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]], '2026-09-21', [], $inactive);
        $this->assignTo($template, $this->empA2, AssignmentRole::Colaborador);

        $this->generate();

        $instance = $template->instances()->orderBy('occurrence_date')->firstOrFail();
        $this->assertSame([[$this->empA2->id, 'colaborador']], $instance->assignments->map(fn ($a): array => [$a->user_id, $a->role->value])->all());
        $this->assertNotNull(ActivityLogEntry::query()->where('subject_id', $instance->id)->where('event', 'assignee_skipped')->first());
    }

    // --- Independencia y control de la plantilla -------------------------------------------------------------------------------------------

    public function test_instances_are_independent_from_the_template_and_from_each_other(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]], '2026-09-21', [], $this->empA1);
        $this->generate();
        [$first, $second] = $template->instances()->orderBy('occurrence_date')->get()->all();

        $this->signIn($this->empA1)->post("/activities/{$first->id}/transition", ['status' => 'in_progress'])->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::InProgress, $first->fresh()->status);
        $this->assertSame(TicketStatus::Pending, $second->fresh()->status);
        $this->assertSame(TicketStatus::Pending, $template->fresh()->status);
    }

    public function test_editing_the_template_only_affects_future_instances(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]], '2026-09-21');
        $this->generate();

        $template->update(['title' => 'Título nuevo']);
        Carbon::setTestNow('2026-10-02 15:00:00');
        $this->generate();

        $byDate = $template->instances()->get()->keyBy(fn (Activity $instance): string => $instance->occurrence_date->toDateString());
        $this->assertSame('Reporte semanal', $byDate['2026-09-28']->title);
        $this->assertSame('Reporte semanal', $byDate['2026-10-05']->title);
        $this->assertSame('Título nuevo', $byDate['2026-10-12']->title);
    }

    public function test_cancelling_the_template_stops_generation_and_reopening_resumes_it(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]], '2026-09-21');
        $this->generate();
        $before = $template->instances()->count();

        $template->forceFill(['status' => TicketStatus::Cancelled])->save();
        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->assertSame(0, $this->generate());
        $this->assertSame($before, $template->instances()->count());

        $template->forceFill(['status' => TicketStatus::Pending])->save();
        $this->assertGreaterThan(0, $this->generate());
    }

    public function test_a_deleted_template_generates_nothing_and_existing_instances_survive(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]], '2026-09-21');
        $this->generate();
        $before = $template->instances()->count();

        $template->delete();
        Carbon::setTestNow('2026-10-20 15:00:00');

        $this->assertSame(0, $this->generate());
        $this->assertSame($before, Activity::query()->where('parent_activity_id', $template->id)->count());
    }

    public function test_only_templates_generate_and_plain_activities_are_ignored(): void
    {
        $plain = $this->makeActivity($this->teamA);
        $instanceParent = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]]);
        $this->generate();

        $this->assertSame(0, $plain->instances()->count());
        $this->assertSame(0, Activity::query()->where('parent_activity_id', '!=', $instanceParent->id)->count());
    }

    // --- Comando y programación ----------------------------------------------------------------------------------------------------------

    public function test_the_command_generates_reports_and_is_idempotent(): void
    {
        $template = $this->template(['frequency' => 'weekly', 'days_of_week' => [1, 3]]);

        $this->artisan('activities:generate-recurring')->expectsOutputToContain('Instancias generadas: 4')->assertSuccessful();
        $this->artisan('activities:generate-recurring')->expectsOutputToContain('Instancias generadas: 0')->assertSuccessful();

        $this->assertSame(4, $template->instances()->count());
    }

    public function test_a_broken_template_does_not_stop_the_others_and_makes_the_command_fail(): void
    {
        $broken = $this->template(['frequency' => 'bogus']);
        $good = $this->template(['frequency' => 'weekly', 'days_of_week' => [1]]);

        $exit = Artisan::call('activities:generate-recurring');

        $this->assertSame(1, $exit);
        $this->assertSame(0, $broken->instances()->count());
        $this->assertGreaterThan(0, $good->instances()->count());
    }

    public function test_the_command_is_scheduled_once_daily_in_business_time_without_overlapping(): void
    {
        Artisan::call('schedule:list');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'activities:generate-recurring'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame('America/Cancun', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
