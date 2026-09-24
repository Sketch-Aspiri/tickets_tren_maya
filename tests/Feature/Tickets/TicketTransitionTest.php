<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class TicketTransitionTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

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

    private function move(User $actor, Ticket $ticket, TicketStatus $to, ?string $comment = null): TestResponse
    {
        return $this->signIn($actor)->post("/tickets/{$ticket->id}/transition", array_filter([
            'status' => $to->value,
            'comment' => $comment,
        ], fn ($value) => $value !== null));
    }

    private function ticketFor(User $assignee, TicketStatus $status): Ticket
    {
        return Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->status($status)->assignedTo($assignee)->create();
    }

    // --- Ciclo completo (criterio "Listo cuando" del sprint) -------------------------------

    public function test_full_flow_employee_to_review_then_coordinator_approves_with_history(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        $ticket = $this->ticketFor($this->empA1, TicketStatus::Pending);

        $this->move($this->empA1, $ticket, TicketStatus::InProgress)->assertRedirect(route('tickets.show', $ticket))->assertSessionHas('status', 'ticket-status-changed');
        $this->move($this->empA1, $ticket, TicketStatus::InReview, 'Listo para revisar')->assertRedirect();

        $this->assertSame(TicketStatus::InReview, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->completed_at);

        Carbon::setTestNow('2026-09-25 11:30:00');
        $this->move($this->coordA, $ticket, TicketStatus::Completed, 'Aprobado')->assertRedirect();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Completed, $fresh->status);
        $this->assertSame('2026-09-25 11:30:00', $fresh->completed_at->toDateTimeString());

        $rows = $ticket->statusHistories()->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([TicketStatus::Pending, TicketStatus::InProgress, TicketStatus::InReview], $rows->pluck('from_status')->all());
        $this->assertSame([TicketStatus::InProgress, TicketStatus::InReview, TicketStatus::Completed], $rows->pluck('to_status')->all());
        $this->assertSame([$this->empA1->id, $this->empA1->id, $this->coordA->id], $rows->pluck('user_id')->all());
        $this->assertSame([null, 'Listo para revisar', 'Aprobado'], $rows->pluck('comment')->all());
    }

    public function test_jefe_can_also_approve_any_team_ticket(): void
    {
        $ticket = $this->ticketFor($this->empB1, TicketStatus::InReview);
        $ticket->forceFill(['team_id' => $this->teamB->id])->save();

        $this->move($this->jefe, $ticket, TicketStatus::Completed)->assertRedirect();

        $this->assertSame(TicketStatus::Completed, $ticket->fresh()->status);
    }

    // --- Rechazo y reabrir exigen comentario ---------------------------------------------------

    public function test_rejecting_requires_a_comment_and_returns_the_ticket_to_in_progress(): void
    {
        $ticket = $this->ticketFor($this->empA1, TicketStatus::InReview);

        $this->from('/tickets')->move($this->coordA, $ticket, TicketStatus::InProgress)
            ->assertSessionHas('error', __('tickets.errors.comment_required'));
        $this->move($this->coordA, $ticket, TicketStatus::InProgress, '   ')
            ->assertSessionHas('error', __('tickets.errors.comment_required'));
        $this->assertSame(TicketStatus::InReview, $ticket->fresh()->status);
        $this->assertSame(0, $ticket->statusHistories()->count());

        $this->move($this->coordA, $ticket, TicketStatus::InProgress, 'Falta evidencia')->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame('Falta evidencia', $ticket->statusHistories()->firstOrFail()->comment);
    }

    public function test_reopening_requires_a_comment_clears_completed_at_and_goes_to_pending(): void
    {
        $completed = Ticket::factory()->forTeam($this->teamA)->completed()->create();
        $cancelled = Ticket::factory()->forTeam($this->teamA)->cancelled()->create();

        foreach ([$completed, $cancelled] as $ticket) {
            $this->move($this->coordA, $ticket, TicketStatus::Pending)->assertSessionHas('error', __('tickets.errors.comment_required'));
            $this->assertTrue($ticket->fresh()->status->isFinal());

            $this->move($this->coordA, $ticket, TicketStatus::Pending, 'Se reabre por error de captura')->assertSessionHasNoErrors();

            $fresh = $ticket->fresh();
            $this->assertSame(TicketStatus::Pending, $fresh->status);
            $this->assertNull($fresh->completed_at);
        }
    }

    // --- Cancelar ---------------------------------------------------------------------------------

    #[DataProvider('openStatuses')]
    public function test_manager_can_cancel_from_any_open_state_without_a_comment(TicketStatus $status): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->status($status)->create();

        $this->move($this->coordA, $ticket, TicketStatus::Cancelled)->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::Cancelled, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->completed_at);
    }

    /**
     * @return array<string, array{0: TicketStatus}>
     */
    public static function openStatuses(): array
    {
        return [
            'pending' => [TicketStatus::Pending],
            'in progress' => [TicketStatus::InProgress],
            'in review' => [TicketStatus::InReview],
        ];
    }

    public function test_a_final_ticket_cannot_be_cancelled_again(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->completed()->create();

        $this->move($this->jefe, $ticket, TicketStatus::Cancelled)->assertSessionHas('error');

        $this->assertSame(TicketStatus::Completed, $ticket->fresh()->status);
    }

    // --- El empleado nunca completa, cancela ni reabre ---------------------------------------------

    #[DataProvider('everyStatus')]
    public function test_employee_can_never_complete_cancel_or_reopen_own_tickets(TicketStatus $from): void
    {
        $ticket = $this->ticketFor($this->empA1, $from);

        foreach ([TicketStatus::Completed, TicketStatus::Cancelled, TicketStatus::Pending] as $forbidden) {
            $this->move($this->empA1, $ticket, $forbidden, 'intento')->assertForbidden();
        }

        $this->assertSame($from, $ticket->fresh()->status);
        $this->assertSame(0, $ticket->statusHistories()->count());
    }

    /**
     * @return array<string, array{0: TicketStatus}>
     */
    public static function everyStatus(): array
    {
        return array_combine(
            array_map(fn (TicketStatus $s) => $s->value, TicketStatus::cases()),
            array_map(fn (TicketStatus $s) => [$s], TicketStatus::cases()),
        );
    }

    public function test_employee_cannot_reject_a_review_back_to_in_progress(): void
    {
        $ticket = $this->ticketFor($this->empA1, TicketStatus::InReview);

        $this->move($this->empA1, $ticket, TicketStatus::InProgress, 'me lo regreso')->assertForbidden();

        $this->assertSame(TicketStatus::InReview, $ticket->fresh()->status);
    }

    public function test_employee_can_advance_only_what_is_theirs(): void
    {
        $mine = $this->ticketFor($this->empA1, TicketStatus::Pending);
        $createdByMe = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();
        $assignedToPeer = $this->ticketFor($this->empA2, TicketStatus::Pending);
        $bagTicket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();

        $this->move($this->empA1, $mine, TicketStatus::InProgress)->assertSessionHasNoErrors();
        $this->move($this->empA1, $createdByMe, TicketStatus::InProgress)->assertSessionHasNoErrors();
        // El compañero de equipo ve la bolsa, pero para trabajarla debe tomarla primero.
        $this->move($this->empA1, $bagTicket, TicketStatus::InProgress)->assertForbidden();
        // Lo asignado a otra persona ni siquiera es visible para el empleado (404).
        $this->move($this->empA1, $assignedToPeer, TicketStatus::InProgress)->assertNotFound();

        $this->assertSame(TicketStatus::Pending, $assignedToPeer->fresh()->status);
        $this->assertSame(TicketStatus::Pending, $bagTicket->fresh()->status);
    }

    // --- Transiciones invalidas (maquina de estados) -------------------------------------------------

    /**
     * @return array<string, array{0: TicketStatus, 1: TicketStatus}>
     */
    public static function invalidTransitions(): array
    {
        $cases = [];

        foreach (TicketStatus::cases() as $from) {
            foreach (TicketStatus::cases() as $to) {
                if ($from !== $to && ! $from->canTransitionTo($to)) {
                    $cases["{$from->value} -> {$to->value}"] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('invalidTransitions')]
    public function test_jefe_gets_a_business_error_for_every_invalid_transition(TicketStatus $from, TicketStatus $to): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->status($from)->create();

        $this->from('/tickets')->move($this->jefe, $ticket, $to, 'comentario')
            ->assertRedirect('/tickets')
            ->assertSessionHas('error', __('tickets.errors.invalid_transition', ['from' => $from->label(), 'to' => $to->label()]));

        $this->assertSame($from, $ticket->fresh()->status);
        $this->assertSame(0, $ticket->statusHistories()->count());
    }

    public function test_same_state_transition_is_invalid(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->create();

        $this->move($this->jefe, $ticket, TicketStatus::Pending, 'x')->assertSessionHas('error');
    }

    public function test_status_graph_is_defined_in_one_place(): void
    {
        $this->assertSame([TicketStatus::InProgress, TicketStatus::Cancelled], TicketStatus::Pending->allowedTargets());
        $this->assertSame([TicketStatus::InReview, TicketStatus::Cancelled], TicketStatus::InProgress->allowedTargets());
        $this->assertSame([TicketStatus::Completed, TicketStatus::InProgress, TicketStatus::Cancelled], TicketStatus::InReview->allowedTargets());
        $this->assertSame([TicketStatus::Pending], TicketStatus::Completed->allowedTargets());
        $this->assertSame([TicketStatus::Pending], TicketStatus::Cancelled->allowedTargets());
    }

    public function test_request_validation_rejects_unknown_or_missing_status(): void
    {
        $ticket = $this->ticketFor($this->empA1, TicketStatus::Pending);

        $this->signIn($this->coordA)->post("/tickets/{$ticket->id}/transition", [])->assertInvalid('status');
        $this->signIn($this->coordA)->post("/tickets/{$ticket->id}/transition", ['status' => 'overdue'])->assertInvalid('status');
        $this->signIn($this->coordA)->post("/tickets/{$ticket->id}/transition", ['status' => 'in_progress', 'comment' => str_repeat('a', 2001)])->assertInvalid('comment');
    }

    // --- Service: defensa en profundidad -----------------------------------------------------------------

    public function test_service_rechecks_authorization_even_when_called_directly(): void
    {
        $ticket = $this->ticketFor($this->empA1, TicketStatus::InReview);

        $this->expectException(AuthorizationException::class);

        app(TicketService::class)->transition($this->empA1, $ticket, TicketStatus::Completed);
    }

    public function test_service_rejects_a_stale_ticket_instance(): void
    {
        $ticket = $this->ticketFor($this->empA1, TicketStatus::Pending);
        $stale = Ticket::query()->findOrFail($ticket->id);

        app(TicketService::class)->transition($this->coordA, $ticket, TicketStatus::Cancelled);

        // La instancia vieja aun dice "Pendiente", pero el servicio relee la fila bloqueada.
        $this->expectException(BusinessRuleException::class);

        app(TicketService::class)->transition($this->coordA, $stale, TicketStatus::InProgress);
    }

    // --- Bitacora ----------------------------------------------------------------------------------------------

    public function test_every_status_change_is_audited_with_old_and_new_values(): void
    {
        $ticket = $this->ticketFor($this->empA1, TicketStatus::Pending);

        $this->move($this->empA1, $ticket, TicketStatus::InProgress)->assertSessionHasNoErrors();

        $activity = Activity::query()->where('subject_type', 'ticket')->where('subject_id', $ticket->id)->where('event', 'status_changed')->firstOrFail();
        $this->assertSame($this->empA1->id, $activity->causer_id);
        $this->assertSame('pending', $activity->attribute_changes['old']['status']);
        $this->assertSame('in_progress', $activity->attribute_changes['attributes']['status']);
        $this->assertSame($ticket->folio, $activity->properties['folio']);

        // El evento generico "updated" del modelo se suprime: solo hay UN registro por cambio.
        $this->assertSame(0, Activity::query()->where('subject_type', 'ticket')->where('subject_id', $ticket->id)->where('event', 'updated')->count());
    }

    // --- Acciones mostradas segun el rol (UX; la autorizacion real es Policy/Service) ---------------------------------

    public function test_available_transitions_reflect_role_and_ownership(): void
    {
        $service = app(TicketService::class);
        $review = $this->ticketFor($this->empA1, TicketStatus::InReview);
        $pending = $this->ticketFor($this->empA1, TicketStatus::Pending);

        $this->assertSame([], $service->availableTransitions($this->empA1, $review));
        $this->assertSame([TicketStatus::InProgress], $service->availableTransitions($this->empA1, $pending));
        $this->assertSame([TicketStatus::Completed, TicketStatus::InProgress, TicketStatus::Cancelled], $service->availableTransitions($this->coordA, $review));
        $this->assertSame([TicketStatus::Completed, TicketStatus::InProgress, TicketStatus::Cancelled], $service->availableTransitions($this->jefe, $review));
        $this->assertSame([], $service->availableTransitions($this->coordB, $review), 'otro equipo: sin acciones');
    }

    public function test_show_page_only_offers_the_actions_the_user_can_take(): void
    {
        $ticket = $this->ticketFor($this->empA1, TicketStatus::InReview);

        $this->signIn($this->empA1)->get("/tickets/{$ticket->id}")->assertOk()
            ->assertDontSee(__('tickets.actions.transition.completed'))
            ->assertDontSee(__('tickets.actions.transition.cancelled'));

        $this->signIn($this->coordA)->get("/tickets/{$ticket->id}")->assertOk()
            ->assertSee(__('tickets.actions.transition.completed'))
            ->assertSee(__('tickets.actions.transition.reject'))
            ->assertSee(__('tickets.actions.transition.cancelled'));
    }

    public function test_transitions_are_rate_limited(): void
    {
        config(['tickets.rate_limits.ticket_write_per_minute' => 2]);
        $ticket = $this->ticketFor($this->empA1, TicketStatus::Pending);

        $this->move($this->coordA, $ticket, TicketStatus::InProgress)->assertRedirect();
        $this->move($this->coordA, $ticket, TicketStatus::InReview)->assertRedirect();
        $this->move($this->coordA, $ticket, TicketStatus::Completed)->assertStatus(429);
    }
}
