<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\PendingScope;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * "Mis pendientes": el coordinador alterna entre lo asignado a el (`mine`, por defecto) y lo abierto de su equipo
 * (`team`). Nadie mas puede ensanchar su vista con el parametro.
 */
class PendingScopeToggleTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    /**
     * @return array{tickets: list<int>, activities: list<int>}
     */
    private function pending(User $actor, string $query = ''): array
    {
        $response = $this->signIn($actor)->get('/tickets/pending'.$query)->assertOk();

        return [
            'tickets' => collect($response->viewData('tickets')->items())->pluck('id')->all(),
            'activities' => collect($response->viewData('activities')->items())->pluck('id')->all(),
        ];
    }

    public function test_default_view_of_a_coordinator_is_their_own_pending_work(): void
    {
        $mine = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->assignedTo($this->coordA)->create();
        $this->makeTicket($this->teamA, $this->empA1);
        $outsideMine = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->assignedTo($this->coordA)->create();

        $this->assertEqualsCanonicalizing([$mine->id, $outsideMine->id], $this->pending($this->coordA)['tickets']);
        $this->assertSame($this->pending($this->coordA), $this->pending($this->coordA, '?scope=mine'));
    }

    public function test_team_view_lists_open_team_tickets_and_activities_assigned_or_not(): void
    {
        $bag = $this->makeTicket($this->teamA, $this->empA1);
        $assignedToPeer = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->assignedTo($this->empA2)->inProgress()->create();
        $mine = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->assignedTo($this->coordA)->inReview()->create();
        $activity = $this->makeActivity($this->teamA, [], $this->empA2);
        $unassignedActivity = $this->makeActivity($this->teamA);

        // Excluidos: finales, eliminados, de otro equipo, plantillas de recurrencia y lo asignado al coordinador fuera de su equipo.
        $this->makeTicket($this->teamA, $this->empA1, ['status' => TicketStatus::Completed]);
        $this->makeTicket($this->teamA, $this->empA1, ['status' => TicketStatus::Cancelled]);
        $this->makeTicket($this->teamA, $this->empA1)->delete();
        $this->makeTicket($this->teamB, $this->empB1);
        Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->assignedTo($this->coordA)->create();
        $this->makeActivity($this->teamA, ['status' => TicketStatus::Completed]);
        $this->makeActivity($this->teamB);
        Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->recurring()->create();

        $team = $this->pending($this->coordA, '?scope=team');

        $this->assertEqualsCanonicalizing([$bag->id, $assignedToPeer->id, $mine->id], $team['tickets']);
        $this->assertEqualsCanonicalizing([$activity->id, $unassignedActivity->id], $team['activities']);
    }

    public function test_team_view_keeps_the_due_date_then_priority_order(): void
    {
        $late = $this->makeTicket($this->teamA, $this->empA1, ['due_date' => '2030-03-01', 'priority' => Priority::Urgent]);
        $soonLow = $this->makeTicket($this->teamA, $this->empA1, ['due_date' => '2030-01-01', 'priority' => Priority::Low]);
        $soonUrgent = $this->makeTicket($this->teamA, $this->empA1, ['due_date' => '2030-01-01', 'priority' => Priority::Urgent]);
        $noDate = $this->makeTicket($this->teamA, $this->empA1, ['priority' => Priority::Urgent]);

        $this->assertSame([$soonUrgent->id, $soonLow->id, $late->id, $noDate->id], $this->pending($this->coordA, '?scope=team')['tickets']);
    }

    public function test_each_coordinator_only_sees_their_own_team(): void
    {
        $a = $this->makeTicket($this->teamA, $this->empA1);
        $b = $this->makeTicket($this->teamB, $this->empB1);

        $this->assertSame([$a->id], $this->pending($this->coordA, '?scope=team')['tickets']);
        $this->assertSame([$b->id], $this->pending($this->coordB, '?scope=team')['tickets']);
    }

    public function test_the_toggle_is_shown_only_to_coordinators_with_the_active_view_marked(): void
    {
        $team = route('tickets.pending', ['scope' => 'team']);

        $this->signIn($this->coordA)->get('/tickets/pending')->assertOk()
            ->assertSee($team, false)
            ->assertSee(__('tickets.pending_scope.mine'))
            ->assertSee(__('tickets.pending_scope.team'))
            ->assertSee(__('tickets.pending_intro'))
            ->assertDontSee(__('tickets.pending_intro_team'));

        $html = $this->signIn($this->coordA)->get('/tickets/pending?scope=team')->assertOk()
            ->assertSee(__('tickets.pending_intro_team'))
            ->getContent();
        preg_match('/<nav aria-label="'.preg_quote(__('tickets.pending_scope.legend'), '/').'".*?<\/nav>/s', $html, $toggle);
        $this->assertSame(1, substr_count($toggle[0], 'aria-current="page"'), 'solo la vista activa se marca en el selector');
        $this->assertMatchesRegularExpression('/href="[^"]*scope=team"\s+aria-current="page"/', $html);

        $this->signIn($this->empA1)->get('/tickets/pending')->assertOk()->assertDontSee($team, false);
        $this->signIn($this->jefe)->get('/tickets/pending')->assertOk()->assertDontSee($team, false);
    }

    public function test_others_cannot_widen_their_view_with_the_parameter(): void
    {
        $bag = $this->makeTicket($this->teamA, $this->empA1);
        $mineEmp = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->assignedTo($this->empA1)->create();

        $this->assertSame([$mineEmp->id], $this->pending($this->empA1, '?scope=team')['tickets']);
        $this->assertSame([], $this->pending($this->jefe, '?scope=team')['tickets'], 'el jefe no tiene equipo: se ignora');
        $this->assertNotContains($bag->id, $this->pending($this->empA2, '?scope=team')['tickets']);
    }

    public function test_a_coordinator_without_a_team_falls_back_to_their_own_view(): void
    {
        $this->makeTicket($this->teamA, $this->empA1);
        $this->coordA->forceFill(['team_id' => null])->save();

        $this->assertFalse(PendingScope::canUseTeam($this->coordA->fresh()));
        $this->assertSame([], $this->pending($this->coordA->fresh(), '?scope=team')['tickets']);
    }

    public function test_inactive_or_unassigned_role_users_cannot_use_the_team_scope(): void
    {
        $this->assertFalse(PendingScope::canUseTeam(User::factory()->coordinador()->inactive()->create()));
        $this->assertFalse(PendingScope::canUseTeam(User::factory()->active()->create(['team_id' => $this->teamA->id])));
        $this->assertTrue(PendingScope::canUseTeam($this->coordA));
    }

    public function test_an_unknown_scope_value_is_rejected(): void
    {
        $this->signIn($this->coordA)->get('/tickets/pending?scope=all')->assertSessionHasErrors('scope');
        $this->signIn($this->coordA)->get('/tickets/pending?scope[]=team')->assertSessionHasErrors('scope');
    }

    public function test_pagination_keeps_the_selected_scope(): void
    {
        foreach (range(1, (int) config('tickets.tickets_per_page') + 1) as $i) {
            $this->makeTicket($this->teamA, $this->empA1);
        }

        $html = $this->signIn($this->coordA)->get('/tickets/pending?scope=team')->assertOk()->getContent();

        $this->assertStringContainsString('scope=team&amp;page=2', $html);
    }

    public function test_the_empty_team_view_has_its_own_message(): void
    {
        $this->signIn($this->coordA)->get('/tickets/pending?scope=team')->assertOk()->assertSee(__('tickets.pending_empty_team'));
    }

    public function test_the_page_has_no_inline_scripts_or_handlers(): void
    {
        $response = $this->signIn($this->coordA)->get('/tickets/pending?scope=team')->assertOk();

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $response->getContent());
    }
}
