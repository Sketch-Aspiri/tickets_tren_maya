<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\Carbon;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

class ActivityCrudTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Reporte semanal de avance',
            'description' => 'Consolidar el avance de la semana.',
            'priority' => Priority::High->value,
            'due_date' => '2030-01-15',
            'responsible_id' => $this->empA1->id,
            ...$overrides,
        ];
    }

    private function nextYearFolio(): string
    {
        return sprintf('ACT-%d-0001', LocalTime::year());
    }

    // --- Crear ---------------------------------------------------------------------------------------------------------------------

    public function test_coordinator_creates_an_activity_for_own_team_with_folio_history_assignment_and_audit(): void
    {
        Carbon::setTestNow('2026-09-24 15:00:00');

        $response = $this->signIn($this->coordA)->post('/activities', $this->payload([
            'collaborator_ids' => [$this->empA2->id],
            'team_id' => $this->teamB->id, // ignorado: un coordinador siempre usa su equipo
        ]));

        $activity = Activity::query()->firstOrFail();
        $response->assertRedirect(route('activities.show', $activity))->assertSessionHas('status', 'activity-created');

        $this->assertSame('ACT-2026-0001', $activity->folio);
        $this->assertSame(TicketStatus::Pending, $activity->status);
        $this->assertSame($this->teamA->id, $activity->team_id);
        $this->assertSame($this->coordA->id, $activity->created_by);
        $this->assertSame(Priority::High, $activity->priority);
        $this->assertFalse($activity->isTemplate());
        $this->assertNull($activity->completed_at);

        $assignments = $activity->assignments()->orderBy('id')->get();
        $this->assertSame([$this->empA1->id, $this->empA2->id], $assignments->pluck('user_id')->all());
        $this->assertSame([AssignmentRole::Responsable, AssignmentRole::Colaborador], $assignments->pluck('role')->all());

        $history = $activity->statusHistories()->firstOrFail();
        $this->assertNull($history->from_status);
        $this->assertSame(TicketStatus::Pending, $history->to_status);
        $this->assertSame($this->coordA->id, $history->user_id);

        $events = ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $activity->id)->pluck('event')->all();
        $this->assertContains('created', $events);
        $this->assertContains('assigned', $events);
    }

    public function test_jefe_chooses_the_team_and_can_assign_across_teams(): void
    {
        $this->signIn($this->jefe)->post('/activities', $this->payload(['team_id' => $this->teamB->id, 'responsible_id' => $this->empB1->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->teamB->id, Activity::query()->firstOrFail()->team_id);
    }

    public function test_jefe_must_choose_a_team(): void
    {
        $this->signIn($this->jefe)->post('/activities', $this->payload())->assertInvalid(['team_id']);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_employees_cannot_create_activities(): void
    {
        $this->signIn($this->empA1)->get('/activities/create')->assertForbidden();
        $this->signIn($this->empA1)->post('/activities', $this->payload())->assertForbidden();
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_folios_are_consecutive_and_restart_each_business_year(): void
    {
        // 2027-01-01 03:00 UTC = 2026-12-31 22:00 en Cancún.
        Carbon::setTestNow('2027-01-01 03:00:00');
        $this->signIn($this->coordA)->post('/activities', $this->payload(['title' => 'Uno']));
        $this->signIn($this->coordA)->post('/activities', $this->payload(['title' => 'Dos']));
        // 2027-01-01 05:30 UTC = 2027-01-01 00:30 en Cancún.
        Carbon::setTestNow('2027-01-01 05:30:00');
        $this->signIn($this->coordA)->post('/activities', $this->payload(['title' => 'Tres', 'due_date' => '2030-01-15']));

        $this->assertSame(['ACT-2026-0001', 'ACT-2026-0002', 'ACT-2027-0001'], Activity::query()->orderBy('id')->pluck('folio')->all());
    }

    public function test_activity_and_ticket_folios_use_independent_counters(): void
    {
        $this->signIn($this->coordA)->post('/tickets', ['title' => 'T', 'description' => 'd', 'priority' => 'low']);
        $this->signIn($this->coordA)->post('/activities', $this->payload());

        $this->assertSame($this->nextYearFolio(), Activity::query()->firstOrFail()->folio);
        $this->assertSame(sprintf('TM-%d-0001', LocalTime::year()), Ticket::query()->firstOrFail()->folio);
    }

    // --- Validación ---------------------------------------------------------------------------------------------------------------

    public function test_creation_validates_each_field(): void
    {
        $this->signIn($this->coordA);
        $inactive = Category::factory()->create(['active' => false]);

        $invalid = [
            'title required' => [['title' => ''], 'title'],
            'title too long' => [['title' => str_repeat('a', 256)], 'title'],
            'description required' => [['description' => ''], 'description'],
            'priority enum' => [['priority' => 'critical'], 'priority'],
            'due date in the past' => [['due_date' => '2020-01-01'], 'due_date'],
            'due date bad format' => [['due_date' => '15/01/2030'], 'due_date'],
            'due before start' => [['start_date' => '2030-02-01', 'due_date' => '2030-01-15'], 'due_date'],
            'start bad format' => [['start_date' => 'mañana'], 'start_date'],
            'category inactive' => [['category_id' => $inactive->id], 'category_id'],
            'category missing' => [['category_id' => 9999], 'category_id'],
            'responsible required' => [['responsible_id' => null], 'responsible_id'],
            'responsible missing' => [['responsible_id' => 9999], 'responsible_id'],
            'too many collaborators' => [['collaborator_ids' => range(1, 11)], 'collaborator_ids'],
        ];

        foreach ($invalid as [$overrides, $field]) {
            $this->post('/activities', $this->payload($overrides))->assertInvalid([$field]);
        }

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_valid_payload_with_optional_fields_passes(): void
    {
        $category = Category::factory()->create();

        $this->signIn($this->coordA)->post('/activities', $this->payload(['category_id' => $category->id, 'start_date' => '2030-01-10']))
            ->assertValid();

        $activity = Activity::query()->firstOrFail();
        $this->assertSame($category->id, $activity->category_id);
        $this->assertSame('2030-01-10', $activity->start_date->toDateString());
        $this->assertSame('2030-01-15', $activity->due_date->toDateString());
    }

    public function test_mass_assignment_cannot_set_protected_columns(): void
    {
        $this->signIn($this->coordA)->post('/activities', $this->payload([
            'folio' => 'ACT-HACK',
            'status' => TicketStatus::Completed->value,
            'created_by' => $this->jefe->id,
            'completed_at' => '2020-01-01 00:00:00',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1],
            'parent_activity_id' => 1,
            'occurrence_date' => '2030-01-01',
        ]))->assertSessionHasNoErrors();

        $activity = Activity::query()->firstOrFail();
        $this->assertSame($this->nextYearFolio(), $activity->folio);
        $this->assertSame(TicketStatus::Pending, $activity->status);
        $this->assertSame($this->coordA->id, $activity->created_by);
        $this->assertNull($activity->completed_at);
        $this->assertNull($activity->recurrence_rule);
        $this->assertNull($activity->parent_activity_id);
        $this->assertNull($activity->occurrence_date);
    }

    // --- Asignación en la creación (reglas de AssignmentService) ---------------------------------------------------------------

    public function test_coordinator_cannot_assign_people_outside_the_team_or_inactive_people_at_creation(): void
    {
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $pending = User::factory()->pending()->create();

        foreach ([$this->empB1, $inactive, $pending] as $candidate) {
            $this->signIn($this->coordA)->from('/activities/create')->post('/activities', $this->payload(['responsible_id' => $candidate->id]))
                ->assertRedirect('/activities/create')->assertSessionHas('error');
        }

        $this->signIn($this->coordA)->post('/activities', $this->payload(['collaborator_ids' => [$this->empB1->id]]))->assertSessionHas('error');
        $this->assertSame(0, Activity::query()->count(), 'una asignación inválida no deja la actividad a medias');
    }

    // --- Leer / editar / eliminar ---------------------------------------------------------------------------------------------------

    public function test_show_renders_for_a_manager_and_for_an_assigned_employee_only(): void
    {
        $activity = $this->makeActivity($this->teamA, ['title' => 'Visible'], $this->empA1);

        $this->signIn($this->coordA)->get("/activities/{$activity->id}")->assertOk()->assertSee('Visible');
        $this->signIn($this->empA1)->get("/activities/{$activity->id}")->assertOk();
        $this->signIn($this->empA2)->get("/activities/{$activity->id}")->assertNotFound();
        $this->signIn($this->coordB)->get("/activities/{$activity->id}")->assertNotFound();
    }

    public function test_coordinator_updates_form_fields_only(): void
    {
        $activity = $this->makeActivity($this->teamA, ['title' => 'Antes'], $this->empA1);

        $this->signIn($this->coordA)->put("/activities/{$activity->id}", $this->payload([
            'title' => 'Después',
            'status' => TicketStatus::Completed->value,
            'team_id' => $this->teamB->id,
            'folio' => 'ACT-HACK',
        ]))->assertRedirect(route('activities.show', $activity))->assertSessionHas('status', 'activity-updated');

        $fresh = $activity->fresh();
        $this->assertSame('Después', $fresh->title);
        $this->assertSame(TicketStatus::Pending, $fresh->status);
        $this->assertSame($this->teamA->id, $fresh->team_id);
        $this->assertNotSame('ACT-HACK', $fresh->folio);

        $this->assertNotNull(ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $activity->id)->where('event', 'updated')->first());
    }

    public function test_update_keeps_a_past_due_date_that_was_already_stored(): void
    {
        $activity = $this->makeActivity($this->teamA, ['due_date' => '2020-05-05'], $this->empA1);

        $this->signIn($this->coordA)->put("/activities/{$activity->id}", $this->payload(['due_date' => '2020-05-05', 'title' => 'Sigue']))->assertValid();
        $this->put("/activities/{$activity->id}", $this->payload(['due_date' => '2020-05-06']))->assertInvalid(['due_date']);
    }

    public function test_update_is_forbidden_for_employees_other_teams_and_final_activities(): void
    {
        $activity = $this->makeActivity($this->teamA, ['title' => 'Intacta'], $this->empA1);
        $done = $this->makeActivity($this->teamA, ['title' => 'Hecha', 'status' => TicketStatus::Completed]);

        $this->signIn($this->empA1)->put("/activities/{$activity->id}", $this->payload())->assertForbidden();
        $this->signIn($this->coordB)->put("/activities/{$activity->id}", $this->payload())->assertNotFound();
        $this->signIn($this->coordA)->put("/activities/{$done->id}", $this->payload())->assertForbidden();
        $this->signIn($this->jefe)->get("/activities/{$done->id}/edit")->assertForbidden();

        $this->assertSame('Intacta', $activity->fresh()->title);
        $this->assertSame('Hecha', $done->fresh()->title);
    }

    public function test_only_pending_or_cancelled_activities_can_be_deleted_and_it_is_soft(): void
    {
        $pending = $this->makeActivity($this->teamA);
        $cancelled = $this->makeActivity($this->teamA, ['status' => TicketStatus::Cancelled]);
        $working = $this->makeActivity($this->teamA, ['status' => TicketStatus::InProgress]);

        $this->signIn($this->coordA)->delete("/activities/{$pending->id}")->assertRedirect(route('activities.index'))->assertSessionHas('status', 'activity-deleted');
        $this->delete("/activities/{$cancelled->id}")->assertSessionHas('status', 'activity-deleted');
        $this->from('/activities')->delete("/activities/{$working->id}")->assertSessionHas('error');

        $this->assertSoftDeleted($pending);
        $this->assertSoftDeleted($cancelled);
        $this->assertNotSoftDeleted($working);
        $this->assertNotNull(ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $pending->id)->where('event', 'deleted')->first());
    }

    public function test_employees_and_other_team_coordinators_cannot_delete(): void
    {
        $activity = $this->makeActivity($this->teamA, [], $this->empA1);

        $this->signIn($this->empA1)->delete("/activities/{$activity->id}")->assertForbidden();
        $this->signIn($this->coordB)->delete("/activities/{$activity->id}")->assertNotFound();
        $this->assertNotSoftDeleted($activity);
    }

    public function test_a_deleted_activity_is_gone_for_everyone(): void
    {
        $activity = $this->makeActivity($this->teamA);
        $activity->delete();

        $this->signIn($this->jefe)->get("/activities/{$activity->id}")->assertNotFound();
    }
}
