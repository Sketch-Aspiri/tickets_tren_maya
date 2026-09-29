<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Presentación compartida del flujo de estados (workflow-stepper / workflow-actions) y del selector de
 * asignados (assignee-picker) en tickets y actividades.
 */
class WorkflowUiTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    private function ticketPage(Ticket $ticket, User $actor): string
    {
        return $this->signIn($actor)->get("/tickets/{$ticket->id}")->assertOk()->getContent();
    }

    private function assignResponsible(Ticket $ticket, User $user): void
    {
        $ticket->assignments()->create(['user_id' => $user->id, 'role' => AssignmentRole::Responsable, 'assigned_by' => $this->jefe->id]);
    }

    public function test_bag_ticket_shows_a_prominent_take_action_to_team_members_only(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $html = $this->ticketPage($ticket, $this->empA2);
        $this->assertStringContainsString('action="'.route('tickets.take', $ticket).'"', $html);
        $this->assertStringContainsString(__('tickets.actions.take'), $html);
        $this->assertStringContainsString(__('workflow.bag.title'), $html);

        $this->assertStringNotContainsString(route('tickets.take', $ticket), $this->ticketPage($ticket, $this->jefe));
        $this->signIn($this->coordB)->get("/tickets/{$ticket->id}")->assertNotFound();
    }

    public function test_assigned_ticket_no_longer_offers_take(): void
    {
        $ticket = $this->makeTicket($this->teamA);
        $this->assignResponsible($ticket, $this->empA1);

        $this->assertStringNotContainsString(route('tickets.take', $ticket), $this->ticketPage($ticket, $this->empA1));
    }

    public function test_primary_action_has_no_optional_comment_field_and_review_actions_keep_the_required_one(): void
    {
        $ticket = $this->makeTicket($this->teamA, null, ['status' => TicketStatus::InProgress]);
        $this->assignResponsible($ticket, $this->empA1);

        $html = $this->ticketPage($ticket, $this->empA1);
        $this->assertStringContainsString(__('tickets.actions.transition.in_review'), $html);
        $this->assertStringNotContainsString('transition_in_review_comment', $html);

        $ticket->forceFill(['status' => TicketStatus::InReview])->save();
        $html = $this->ticketPage($ticket, $this->coordA);
        $this->assertMatchesRegularExpression('/id="transition_reject_comment"[^>]*\srequired/', $html);
        $this->assertStringContainsString(__('workflow.required'), $html);
        $this->assertStringContainsString(__('tickets.actions.transition.completed'), $html);
        $this->assertStringNotContainsString('transition_completed_comment', $html);
    }

    public function test_ticket_has_stepper_and_no_progress_bar_or_subtask_block(): void
    {
        $ticket = $this->makeTicket($this->teamA, null, ['status' => TicketStatus::InReview]);

        $html = $this->ticketPage($ticket, $this->coordA);
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertStringNotContainsString('<progress', $html);
        $this->assertStringNotContainsString('id="subtareas"', $html);
        $this->assertStringNotContainsString('transition_blocked_hint', $html);
    }

    public function test_transition_without_comment_is_accepted_for_tickets(): void
    {
        $ticket = $this->makeTicket($this->teamA, null, ['status' => TicketStatus::Pending]);
        $this->assignResponsible($ticket, $this->empA1);

        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/transition", ['status' => TicketStatus::InProgress->value])
            ->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_activity_primary_action_has_no_optional_comment_and_blocks_with_explanation(): void
    {
        $activity = $this->makeActivity($this->teamA, ['status' => TicketStatus::InProgress], $this->empA1);
        $this->makeSubtask($activity, 'Pendiente');

        $html = $this->signIn($this->empA1)->get("/activities/{$activity->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('transition_in_review_comment', $html);
        $this->assertStringContainsString('transition_blocked_hint', $html);
        $this->assertStringContainsString(__('workflow.blocked', ['count' => 1]), $html);
    }

    public function test_assignee_picker_posts_the_same_fields_and_marks_selected_people(): void
    {
        $activity = $this->makeActivity($this->teamA, [], $this->empA1);
        $this->assignTo($activity, $this->empA2, AssignmentRole::Colaborador);

        $html = $this->signIn($this->coordA)->get("/activities/{$activity->id}")->assertOk()->getContent();

        $this->assertStringContainsString('name="responsible_id"', $html);
        $this->assertStringContainsString('name="collaborator_ids[]"', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$this->empA1->id.'" selected/', $html);
        $this->assertMatchesRegularExpression('/name="collaborator_ids\[\]" value="'.$this->empA2->id.'"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="collaborator_ids\[\]" value="'.$this->empA1->id.'"[^>]*checked/', $html);
        $this->assertStringContainsString('data-max="'.config('tickets.max_collaborators').'"', $html);
        $this->assertStringContainsString(__('workflow.picker.counter', ['count' => 1, 'max' => config('tickets.max_collaborators')]), $html);
        $this->assertStringContainsString('x-data="assigneePicker"', $html);
        // El buscador es solo de interfaz: no lleva `name`, así que nunca viaja en el formulario.
        $this->assertDoesNotMatchRegularExpression('/<input id="assign_filter"[^>]*name=/', $html);
    }

    public function test_assignee_picker_is_used_on_the_ticket_assignment_and_new_activity_forms(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->assertStringContainsString('x-data="assigneePicker"', $this->ticketPage($ticket, $this->coordA));
        $this->signIn($this->coordA)->get('/activities/create')->assertOk()->assertSee('name="collaborator_ids[]"', false);
    }

    public function test_assignee_picker_saves_through_the_unchanged_endpoint(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->signIn($this->coordA)->put("/tickets/{$ticket->id}/assignments", [
            'responsible_id' => $this->empA1->id,
            'collaborator_ids' => [$this->empA2->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $ticket->assignments()->count());
    }
}
