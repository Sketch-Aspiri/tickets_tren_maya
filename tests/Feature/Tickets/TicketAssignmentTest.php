<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\AssignmentRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class TicketAssignmentTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    /**
     * @param  list<int>  $collaborators
     */
    private function assign(User $actor, Ticket $ticket, int $responsible, array $collaborators = []): TestResponse
    {
        return $this->signIn($actor)->put("/tickets/{$ticket->id}/assignments", [
            'responsible_id' => $responsible,
            'collaborator_ids' => $collaborators,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function roles(Ticket $ticket): array
    {
        $roles = $ticket->assignments()->get()->mapWithKeys(fn ($a) => [$a->user_id => $a->role->value])->all();
        ksort($roles);

        return $roles;
    }

    // --- Asignar / reasignar / delegar ----------------------------------------------------------

    public function test_coordinator_assigns_one_responsible_and_several_collaborators_with_audit(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->assign($this->coordA, $ticket, $this->empA1->id, [$this->empA2->id, $this->coordA->id])
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('status', 'ticket-assigned');

        $this->assertEquals([
            $this->empA1->id => 'responsable',
            $this->empA2->id => 'colaborador',
            $this->coordA->id => 'colaborador',
        ], $this->roles($ticket));
        $this->assertSame($this->coordA->id, $ticket->assignments()->first()->assigned_by);

        $activity = Activity::query()->where('subject_type', 'ticket')->where('event', 'assigned')->firstOrFail();
        $this->assertSame($this->coordA->id, $activity->causer_id);
        $this->assertSame([], $activity->attribute_changes['old']['assignments']);
        $this->assertCount(3, $activity->attribute_changes['attributes']['assignments']);
    }

    public function test_reassigning_replaces_the_set_and_logs_reassigned(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA1)->create();

        $this->assign($this->coordA, $ticket, $this->empA2->id, [$this->empA1->id])->assertSessionHasNoErrors();

        // Delegacion: quien era responsable pasa a colaborador y el nuevo es el unico responsable.
        $this->assertEquals([$this->empA2->id => 'responsable', $this->empA1->id => 'colaborador'], $this->roles($ticket));
        $this->assertSame(1, $ticket->assignments()->where('role', AssignmentRole::Responsable->value)->count());

        $this->assign($this->coordA, $ticket, $this->empA2->id, [])->assertSessionHasNoErrors();
        $this->assertSame([$this->empA2->id => 'responsable'], $this->roles($ticket));

        $events = Activity::query()->where('subject_type', 'ticket')->whereIn('event', ['assigned', 'reassigned'])->orderBy('id')->pluck('event')->all();
        $this->assertSame(['reassigned', 'reassigned'], $events);
    }

    public function test_the_responsible_is_never_duplicated_as_collaborator_and_duplicates_collapse(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->signIn($this->coordA)->put("/tickets/{$ticket->id}/assignments", [
            'responsible_id' => $this->empA1->id,
            'collaborator_ids' => [$this->empA1->id, $this->empA2->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$this->empA1->id => 'responsable', $this->empA2->id => 'colaborador'], $this->roles($ticket));

        $this->signIn($this->coordA)->put("/tickets/{$ticket->id}/assignments", [
            'responsible_id' => $this->empA1->id,
            'collaborator_ids' => [$this->empA2->id, $this->empA2->id],
        ])->assertInvalid('collaborator_ids.0');
    }

    public function test_jefe_can_assign_to_any_active_user_including_other_teams(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->assign($this->jefe, $ticket, $this->empB1->id, [$this->coordA->id])->assertSessionHasNoErrors();

        $this->assertEquals([$this->empB1->id => 'responsable', $this->coordA->id => 'colaborador'], $this->roles($ticket));
    }

    public function test_coordinator_can_only_assign_active_members_of_their_own_team(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->assign($this->coordA, $ticket, $this->empB1->id)
            ->assertSessionHas('error', __('tickets.errors.assignee_out_of_team'));
        $this->assign($this->coordA, $ticket, $this->empA1->id, [$this->empB1->id])
            ->assertSessionHas('error', __('tickets.errors.assignee_out_of_team'));
        $this->assertSame([], $this->roles($ticket));
    }

    public function test_cannot_assign_pending_inactive_or_roleless_users_and_nothing_is_saved(): void
    {
        $ticket = $this->makeTicket($this->teamA);
        $pending = User::factory()->pending()->create();
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $noRole = User::factory()->active()->create(['team_id' => $this->teamA->id]);

        foreach ([$pending, $inactive, $noRole] as $candidate) {
            $this->assign($this->jefe, $ticket, $candidate->id)->assertSessionHas('error', __('tickets.errors.invalid_assignee'));
            $this->assign($this->jefe, $ticket, $this->empA1->id, [$candidate->id])->assertSessionHas('error', __('tickets.errors.invalid_assignee'));
        }

        $this->assertSame([], $this->roles($ticket), 'una asignacion invalida no deja asignaciones parciales');
    }

    public function test_validation_of_the_assignment_request(): void
    {
        $ticket = $this->makeTicket($this->teamA);
        $actor = $this->signIn($this->coordA);

        $actor->put("/tickets/{$ticket->id}/assignments", [])->assertInvalid('responsible_id');
        $actor->put("/tickets/{$ticket->id}/assignments", ['responsible_id' => 99999])->assertInvalid('responsible_id');
        $actor->put("/tickets/{$ticket->id}/assignments", ['responsible_id' => 'abc'])->assertInvalid('responsible_id');
        $actor->put("/tickets/{$ticket->id}/assignments", ['responsible_id' => $this->empA1->id, 'collaborator_ids' => 'x'])->assertInvalid('collaborator_ids');
        $actor->put("/tickets/{$ticket->id}/assignments", ['responsible_id' => $this->empA1->id, 'collaborator_ids' => [99999]])->assertInvalid('collaborator_ids.0');

        config(['tickets.max_collaborators' => 1]);
        $actor->put("/tickets/{$ticket->id}/assignments", ['responsible_id' => $this->empA1->id, 'collaborator_ids' => [$this->empA2->id, $this->coordA->id]])->assertInvalid('collaborator_ids');
    }

    public function test_service_enforces_the_collaborator_limit_too(): void
    {
        config(['tickets.max_collaborators' => 1]);
        $ticket = $this->makeTicket($this->teamA);

        $this->expectException(BusinessRuleException::class);

        app(AssignmentService::class)->assign($this->coordA, $ticket, $this->empA1->id, [$this->empA2->id, $this->coordA->id]);
    }

    public function test_employees_cannot_assign_reassign_or_release(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->assignedTo($this->empA1)->create();

        $this->assign($this->empA1, $ticket, $this->empA2->id)->assertForbidden();
        $this->signIn($this->empA1)->delete("/tickets/{$ticket->id}/assignments")->assertForbidden();

        $this->assertSame([$this->empA1->id => 'responsable'], $this->roles($ticket));
    }

    public function test_final_tickets_cannot_be_assigned_or_released(): void
    {
        foreach (['completed', 'cancelled'] as $state) {
            $ticket = Ticket::factory()->forTeam($this->teamA)->{$state}()->assignedTo($this->empA1)->create();

            $this->assign($this->coordA, $ticket, $this->empA2->id)->assertSessionHas('error', __('tickets.errors.closed_assignment'));
            $this->signIn($this->coordA)->delete("/tickets/{$ticket->id}/assignments")->assertSessionHas('error', __('tickets.errors.closed_assignment'));

            $this->assertSame([$this->empA1->id => 'responsable'], $this->roles($ticket));
        }
    }

    // --- Devolver a la bolsa -------------------------------------------------------------------------

    public function test_coordinator_returns_a_ticket_to_the_bag_and_it_is_logged(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA1)->create();

        $this->signIn($this->coordA)->delete("/tickets/{$ticket->id}/assignments")
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('status', 'ticket-unassigned');

        $this->assertSame([], $this->roles($ticket));
        $this->assertSame(1, Activity::query()->where('subject_type', 'ticket')->where('event', 'unassigned')->count());

        $this->signIn($this->coordA)->delete("/tickets/{$ticket->id}/assignments")->assertSessionHas('error', __('tickets.errors.not_assigned'));
    }

    // --- Tomar de la bolsa -------------------------------------------------------------------------------

    public function test_employee_takes_a_bag_ticket_of_their_own_team_and_becomes_responsible(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();

        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/take")
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('status', 'ticket-taken');

        $this->assertSame([$this->empA1->id => 'responsable'], $this->roles($ticket));

        $activity = Activity::query()->where('subject_type', 'ticket')->where('event', 'taken')->firstOrFail();
        $this->assertSame($this->empA1->id, $activity->causer_id);
    }

    public function test_the_second_person_to_take_a_ticket_loses_and_the_first_keeps_it(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();

        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/take")->assertSessionHasNoErrors();
        // La bolsa ya no la muestra a los demas: el segundo intento llega con el ticket asignado a otra persona.
        $this->signIn($this->empA2)->post("/tickets/{$ticket->id}/take")->assertNotFound();

        $this->assertSame([$this->empA1->id => 'responsable'], $this->roles($ticket));
    }

    public function test_service_take_is_atomic_against_a_stale_instance(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();
        $staleForSecond = Ticket::query()->findOrFail($ticket->id);
        $service = app(AssignmentService::class);

        $service->take($this->empA1, $ticket);

        try {
            $service->take($this->empA2, $staleForSecond);
            $this->fail('El segundo take debio fallar');
        } catch (BusinessRuleException|AuthorizationException $exception) {
            $this->assertSame(1, $ticket->assignments()->count());
            $this->assertSame($this->empA1->id, $ticket->assignments()->firstOrFail()->user_id);
        }
    }

    public function test_service_take_reports_already_taken_when_the_ticket_is_still_visible(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA2)->create();
        $service = app(AssignmentService::class);

        $service->take($this->empA1, $ticket);

        // empA2 lo creo, por eso sigue viendolo aunque ya lo tomo otra persona.
        $this->expectExceptionObject(BusinessRuleException::because('tickets.errors.already_taken'));

        $service->take($this->empA2, $ticket);
    }

    public function test_cannot_take_a_ticket_from_another_team_or_as_jefe(): void
    {
        $bagOfB = Ticket::factory()->forTeam($this->teamB)->createdBy($this->coordB)->create();
        $bagOfA = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();

        $this->signIn($this->empA1)->post("/tickets/{$bagOfB->id}/take")->assertNotFound();
        $this->signIn($this->coordA)->post("/tickets/{$bagOfB->id}/take")->assertNotFound();
        $this->signIn($this->jefe)->post("/tickets/{$bagOfA->id}/take")->assertForbidden();

        $this->assertSame([], $this->roles($bagOfA));
        $this->assertSame([], $this->roles($bagOfB));
    }

    public function test_an_employee_who_created_a_ticket_of_another_team_cannot_take_it(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empA1)->create();

        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/take")->assertForbidden();
    }

    public function test_coordinator_can_take_a_bag_ticket_of_their_team(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();

        $this->signIn($this->coordA)->post("/tickets/{$ticket->id}/take")->assertSessionHasNoErrors();

        $this->assertSame([$this->coordA->id => 'responsable'], $this->roles($ticket));
    }

    public function test_cannot_take_a_closed_ticket(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->cancelled()->create();

        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/take")->assertSessionHas('error', __('tickets.errors.closed_assignment'));

        $this->assertSame([], $this->roles($ticket));
    }

    public function test_service_rejects_a_direct_assign_by_an_employee(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);

        $this->expectException(AuthorizationException::class);

        app(AssignmentService::class)->assign($this->empA1, $ticket, $this->empA1->id);
    }

    // --- Base de datos -------------------------------------------------------------------------------------------

    public function test_a_person_cannot_be_assigned_twice_to_the_same_ticket_at_database_level(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA1)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $ticket->assignments()->create(['user_id' => $this->empA1->id, 'role' => AssignmentRole::Colaborador]);
    }

    // --- Vista -------------------------------------------------------------------------------------------------------

    public function test_show_page_offers_take_only_to_those_who_can_and_assign_only_to_managers(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();

        $this->signIn($this->empA1)->get("/tickets/{$ticket->id}")
            ->assertOk()
            ->assertSee(__('tickets.actions.take'))
            ->assertDontSee(__('tickets.assign.submit'));

        $this->signIn($this->coordA)->get("/tickets/{$ticket->id}")
            ->assertOk()
            ->assertSee(__('tickets.assign.submit'))
            // El selector del coordinador solo lista a su equipo.
            ->assertSee($this->empA2->name)
            ->assertDontSee($this->empB1->name);

        $this->signIn($this->jefe)->get("/tickets/{$ticket->id}")
            ->assertSee($this->empB1->name)
            ->assertDontSee(__('tickets.actions.take'));
    }
}
