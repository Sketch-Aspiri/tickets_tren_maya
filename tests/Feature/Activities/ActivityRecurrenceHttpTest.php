<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Alta y edición de actividades recurrentes por la web: validación del editor de recurrencia y
 * comportamiento de la plantilla (madre).
 */
class ActivityRecurrenceHttpTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        Carbon::setTestNow('2026-09-24 15:00:00'); // jueves 24/09/2026 en Cancún
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $recurrence
     * @return array<string, mixed>
     */
    private function payload(array $overrides = [], array $recurrence = []): array
    {
        return [
            'title' => 'Reporte semanal de avance',
            'description' => 'Consolidar el avance.',
            'priority' => Priority::High->value,
            'responsible_id' => $this->empA1->id,
            'collaborator_ids' => [$this->empA2->id],
            'is_recurring' => '1',
            'start_date' => '2026-09-21',
            'due_date' => '2026-09-23',
            'recurrence' => [
                'frequency' => 'weekly',
                'interval' => 1,
                'days_of_week' => [1, 3],
                'ends_at' => '2026-12-31',
                ...$recurrence,
            ],
            ...$overrides,
        ];
    }

    public function test_creating_a_recurring_activity_makes_a_template_and_generates_the_first_instances(): void
    {
        $response = $this->signIn($this->coordA)->post('/activities', $this->payload());

        $template = Activity::query()->templates()->firstOrFail();
        $response->assertRedirect(route('activities.show', $template))->assertSessionHas('status', 'activity-created');

        $this->assertSame([
            'frequency' => 'weekly',
            'interval' => 1,
            'days_of_week' => [1, 3],
            'day_of_month' => null,
            'ends_at' => '2026-12-31',
        ], $template->recurrence_rule);
        $this->assertSame('2026-09-21', $template->start_date->toDateString());
        $this->assertSame(TicketStatus::Pending, $template->status);
        $this->assertTrue($template->isTemplate());

        // Instancias hasta el horizonte (24/09 + 14 días): 28/09, 30/09, 05/10, 07/10.
        $instances = $template->instances()->orderBy('occurrence_date')->get();
        $this->assertSame(['2026-09-28', '2026-09-30', '2026-10-05', '2026-10-07'], $instances->map(fn (Activity $i): string => $i->occurrence_date->toDateString())->all());
        $this->assertSame(['ACT-2026-0002', 'ACT-2026-0003', 'ACT-2026-0004', 'ACT-2026-0005'], $instances->pluck('folio')->all());
        $this->assertSame($this->empA1->id, $instances->first()->assignments()->where('role', 'responsable')->firstOrFail()->user_id);
        $this->assertSame(2, $instances->first()->assignments()->count());
    }

    public function test_a_plain_activity_has_no_recurrence_even_if_the_editor_data_is_sent(): void
    {
        $this->signIn($this->coordA)->post('/activities', $this->payload(['is_recurring' => '0', 'start_date' => null, 'due_date' => null]))->assertSessionHasNoErrors();

        $activity = Activity::query()->firstOrFail();
        $this->assertNull($activity->recurrence_rule);
        $this->assertSame(1, Activity::query()->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function invalidRecurrence(): array
    {
        return [
            'unknown frequency' => [[], ['frequency' => 'hourly'], 'recurrence.frequency'],
            'missing frequency' => [[], ['frequency' => ''], 'recurrence.frequency'],
            'interval zero' => [[], ['interval' => 0], 'recurrence.interval'],
            'interval too large' => [[], ['interval' => 53], 'recurrence.interval'],
            'interval not a number' => [[], ['interval' => 'dos'], 'recurrence.interval'],
            'day of week 8' => [[], ['days_of_week' => [8]], 'recurrence.days_of_week.0'],
            'day of week 0' => [[], ['days_of_week' => [0]], 'recurrence.days_of_week.0'],
            'repeated day of week' => [[], ['days_of_week' => [1, 1]], 'recurrence.days_of_week.0'],
            'day of month 32' => [[], ['frequency' => 'monthly', 'day_of_month' => 32], 'recurrence.day_of_month'],
            'day of month 0' => [[], ['frequency' => 'monthly', 'day_of_month' => 0], 'recurrence.day_of_month'],
            'ends before start' => [[], ['ends_at' => '2026-09-01'], 'recurrence.ends_at'],
            'ends bad format' => [[], ['ends_at' => '31/12/2026'], 'recurrence.ends_at'],
            'start date required' => [['start_date' => ''], [], 'start_date'],
            'recurrence missing' => [['recurrence' => null], [], 'recurrence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $recurrence
     */
    #[DataProvider('invalidRecurrence')]
    public function test_the_recurrence_editor_is_validated(array $overrides, array $recurrence, string $field): void
    {
        $this->signIn($this->coordA)->post('/activities', $this->payload($overrides, $recurrence))->assertInvalid([$field]);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_keys_that_do_not_apply_to_the_frequency_are_discarded(): void
    {
        $this->signIn($this->coordA)->post('/activities', $this->payload([], ['frequency' => 'monthly', 'day_of_month' => '31', 'days_of_week' => [1, 2]]))->assertSessionHasNoErrors();

        $rule = Activity::query()->templates()->firstOrFail()->recurrence_rule;
        $this->assertSame('monthly', $rule['frequency']);
        $this->assertSame(31, $rule['day_of_month']);
        $this->assertSame([], $rule['days_of_week']);
    }

    public function test_a_recurring_due_date_only_needs_to_follow_the_start_date(): void
    {
        // Con inicio en el pasado, la fecha límite de la primera ocurrencia puede ser anterior a hoy.
        $this->signIn($this->coordA)->post('/activities', $this->payload(['start_date' => '2026-09-01', 'due_date' => '2026-09-05']))->assertSessionHasNoErrors();
        $this->post('/activities', $this->payload(['start_date' => '2026-09-10', 'due_date' => '2026-09-05']))->assertInvalid(['due_date']);
    }

    public function test_editing_the_template_changes_the_rule_and_does_not_touch_generated_instances(): void
    {
        $this->signIn($this->coordA)->post('/activities', $this->payload());
        $template = Activity::query()->templates()->firstOrFail();
        $instancesBefore = $template->instances()->orderBy('id')->get(['id', 'title', 'occurrence_date'])->toArray();

        $this->put("/activities/{$template->id}", $this->payload(['title' => 'Título de la serie nuevo'], ['frequency' => 'daily', 'interval' => 2, 'days_of_week' => [], 'ends_at' => null]))
            ->assertRedirect(route('activities.show', $template))->assertSessionHas('status', 'activity-updated');

        $fresh = $template->fresh();
        $this->assertSame('Título de la serie nuevo', $fresh->title);
        $this->assertSame('daily', $fresh->recurrence_rule['frequency']);
        $this->assertSame(2, $fresh->recurrence_rule['interval']);
        $this->assertNull($fresh->recurrence_rule['ends_at']);
        $this->assertSame($instancesBefore, $template->instances()->orderBy('id')->get(['id', 'title', 'occurrence_date'])->toArray());

        $entry = ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $template->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertArrayHasKey('recurrence_rule', $entry->attribute_changes['attributes']);
    }

    public function test_a_template_must_keep_a_valid_recurrence_when_edited(): void
    {
        $this->signIn($this->coordA)->post('/activities', $this->payload());
        $template = Activity::query()->templates()->firstOrFail();

        $this->put("/activities/{$template->id}", $this->payload(['recurrence' => null, 'is_recurring' => '0']))->assertInvalid(['recurrence']);
        $this->assertTrue($template->fresh()->isTemplate());
    }

    public function test_a_plain_activity_or_an_instance_cannot_become_recurring_through_an_edit(): void
    {
        $plain = $this->makeActivity($this->teamA, [], $this->empA1);
        $this->signIn($this->coordA)->put("/activities/{$plain->id}", $this->payload(['start_date' => null, 'due_date' => null]))->assertSessionHasNoErrors();
        $this->assertNull($plain->fresh()->recurrence_rule);

        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => '2026-09-01'], $this->empA1);
        $instance = $this->makeActivity($this->teamA, ['parent_activity_id' => $template->id, 'occurrence_date' => '2026-09-25'], $this->empA1);
        $this->put("/activities/{$instance->id}", $this->payload(['start_date' => null, 'due_date' => null]))->assertSessionHasNoErrors();

        $this->assertNull($instance->fresh()->recurrence_rule);
        $this->assertSame($template->id, $instance->fresh()->parent_activity_id);
        $this->assertSame('2026-09-25', $instance->fresh()->occurrence_date->toDateString());
    }

    public function test_the_template_is_told_apart_in_the_ui_and_hidden_from_employees(): void
    {
        $this->signIn($this->coordA)->post('/activities', $this->payload());
        $template = Activity::query()->templates()->firstOrFail();
        $instance = $template->instances()->orderBy('occurrence_date')->firstOrFail();

        $this->signIn($this->coordA)->get("/activities/{$template->id}")->assertOk()
            ->assertSee(__('activities.badges.template'))->assertSee(__('activities.show.recurrence_rule'))
            ->assertSee(__('activities.show.template_hint'));
        $this->get("/activities/{$instance->id}")->assertOk()->assertSee(__('activities.badges.instance', ['folio' => $template->folio]));

        $this->signIn($this->empA1)->get("/activities/{$template->id}")->assertNotFound();
        $this->get("/activities/{$instance->id}")->assertOk();
    }
}
