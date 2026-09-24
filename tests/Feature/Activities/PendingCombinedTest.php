<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * "Mis pendientes" muestra dos secciones claras: tickets (como antes) y actividades asignadas al usuario.
 */
class PendingCombinedTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    /**
     * @return list<int>
     */
    private function pendingActivityIds(User $actor, string $query = ''): array
    {
        $response = $this->signIn($actor)->get('/tickets/pending'.$query)->assertOk();

        return collect($response->viewData('activities')->items())->pluck('id')->all();
    }

    public function test_it_lists_open_activities_assigned_to_me_ordered_by_due_date_then_priority(): void
    {
        $noDate = $this->makeActivity($this->teamA, ['priority' => Priority::Urgent], $this->empA1);
        $lateLow = $this->makeActivity($this->teamA, ['priority' => Priority::Low, 'due_date' => '2030-03-01'], $this->empA1);
        $soonLow = $this->makeActivity($this->teamA, ['priority' => Priority::Low, 'due_date' => '2030-01-01'], $this->empA1);
        $soonUrgent = $this->makeActivity($this->teamA, ['priority' => Priority::Urgent, 'due_date' => '2030-01-01'], $this->empA1);
        $collab = $this->makeActivity($this->teamA, ['priority' => Priority::Medium, 'due_date' => '2030-02-01']);
        $this->assignTo($collab, $this->empA1, AssignmentRole::Colaborador);

        // Excluidas: estados finales, de otra persona, sin asignar y de otro equipo.
        $this->makeActivity($this->teamA, ['status' => TicketStatus::Completed], $this->empA1);
        $this->makeActivity($this->teamA, ['status' => TicketStatus::Cancelled], $this->empA1);
        $this->makeActivity($this->teamA, [], $this->empA2);
        $this->makeActivity($this->teamA);
        $this->makeActivity($this->teamB, [], $this->empB1);

        $this->assertSame(
            [$soonUrgent->id, $soonLow->id, $collab->id, $lateLow->id, $noDate->id],
            $this->pendingActivityIds($this->empA1),
        );
    }

    public function test_recurring_templates_never_appear_but_their_instances_do(): void
    {
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => '2030-01-01'], $this->coordA);
        $instance = $this->makeActivity($this->teamA, ['parent_activity_id' => $template->id, 'occurrence_date' => '2030-01-02'], $this->coordA);

        $this->assertSame([$instance->id], $this->pendingActivityIds($this->coordA));
    }

    public function test_a_pending_subtask_assigned_to_me_brings_its_activity_but_a_done_one_does_not(): void
    {
        $withOpen = $this->makeActivity($this->teamA);
        $this->makeSubtask($withOpen, 'abierta', false, $this->empA2);
        $withDone = $this->makeActivity($this->teamA);
        $this->makeSubtask($withDone, 'hecha', true, $this->empA2);

        $this->assertSame([$withOpen->id], $this->pendingActivityIds($this->empA2));
    }

    public function test_coordinators_see_only_what_is_assigned_to_them_not_the_whole_team(): void
    {
        $mine = $this->makeActivity($this->teamA, [], $this->coordA);
        $this->makeActivity($this->teamA, [], $this->empA1);

        $this->assertSame([$mine->id], $this->pendingActivityIds($this->coordA));
    }

    public function test_tickets_keep_working_as_before_in_the_same_page(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA1)->create(['title' => 'Ticket pendiente']);
        $this->makeActivity($this->teamA, ['title' => 'Actividad pendiente'], $this->empA1);

        $response = $this->signIn($this->empA1)->get('/tickets/pending')->assertOk()->assertViewIs('tickets.pending')
            ->assertSee('Ticket pendiente')->assertSee('Actividad pendiente')
            ->assertSee(__('tickets.pending_tickets_heading'))->assertSee(__('tickets.pending_activities_heading'));

        $this->assertSame([$ticket->id], collect($response->viewData('tickets')->items())->pluck('id')->all());
    }

    public function test_empty_states_are_explained_per_section(): void
    {
        $this->signIn($this->empA1)->get('/tickets/pending')->assertOk()
            ->assertSee(__('tickets.pending_empty'))->assertSee(__('activities.pending_empty'));
    }

    public function test_each_section_paginates_independently(): void
    {
        Ticket::factory()->count(16)->forTeam($this->teamA)->assignedTo($this->empA1)->create();
        Activity::factory()->count(16)->forTeam($this->teamA)->assignedTo($this->empA1)->create();

        $this->assertCount(15, $this->pendingActivityIds($this->empA1));
        $this->assertCount(1, $this->pendingActivityIds($this->empA1, '?activities_page=2'));

        $response = $this->signIn($this->empA1)->get('/tickets/pending?activities_page=2');
        $this->assertCount(15, $response->viewData('tickets')->items(), 'los tickets siguen en su página 1');

        $this->get('/tickets/pending?activities_page=0')->assertInvalid(['activities_page']);
    }

    public function test_activities_show_a_link_to_the_detail_and_their_progress_as_text(): void
    {
        $activity = $this->makeActivity($this->teamA, ['title' => 'Con avance'], $this->empA1);
        $this->makeSubtask($activity, 'a', true);
        $this->makeSubtask($activity, 'b');

        $this->signIn($this->empA1)->get('/tickets/pending')->assertOk()
            ->assertSee(route('activities.show', $activity), false)
            ->assertSee('50 %', false);
    }
}
