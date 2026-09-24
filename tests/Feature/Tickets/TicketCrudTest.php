<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\Priority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use Carbon\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class TicketCrudTest extends DatabaseTestCase
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

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Falla en la impresora',
            'description' => 'No imprime desde la mañana.',
            'priority' => Priority::High->value,
        ], $overrides);
    }

    // --- Crear -------------------------------------------------------------------

    public function test_employee_creates_a_ticket_in_their_own_team_with_history_and_audit(): void
    {
        $category = Category::factory()->create();

        $response = $this->signIn($this->empA1)->post('/tickets', $this->payload([
            'category_id' => $category->id,
            'due_date' => now()->addDays(5)->toDateString(),
        ]));

        $ticket = Ticket::query()->firstOrFail();
        $response->assertRedirect(route('tickets.show', $ticket))->assertSessionHas('status', 'ticket-created');

        $this->assertSame($this->teamA->id, $ticket->team_id);
        $this->assertSame($this->empA1->id, $ticket->created_by);
        $this->assertSame(TicketStatus::Pending, $ticket->status);
        $this->assertSame(TicketSource::Web, $ticket->source);
        $this->assertSame(Priority::High, $ticket->priority);
        $this->assertSame($category->id, $ticket->category_id);
        $this->assertMatchesRegularExpression('/^TM-\d{4}-0001$/', $ticket->folio);
        $this->assertTrue($ticket->assignments()->doesntExist(), 'nace en la bolsa del equipo');

        $history = $ticket->statusHistories()->firstOrFail();
        $this->assertNull($history->from_status);
        $this->assertSame(TicketStatus::Pending, $history->to_status);
        $this->assertSame($this->empA1->id, $history->user_id);

        $activity = Activity::query()->where('subject_type', 'ticket')->where('subject_id', $ticket->id)->where('event', 'created')->firstOrFail();
        $this->assertSame($this->empA1->id, $activity->causer_id);
        $this->assertSame('Falla en la impresora', $activity->attribute_changes['attributes']['title']);
    }

    public function test_coordinator_ignores_a_team_id_sent_in_the_request(): void
    {
        $this->signIn($this->coordA)->post('/tickets', $this->payload(['team_id' => $this->teamB->id]))->assertSessionHasNoErrors();

        $this->assertSame($this->teamA->id, Ticket::query()->firstOrFail()->team_id);
    }

    public function test_jefe_must_choose_a_team_because_they_have_none(): void
    {
        $this->signIn($this->jefe)->post('/tickets', $this->payload())->assertInvalid('team_id');
        $this->signIn($this->jefe)->post('/tickets', $this->payload(['team_id' => 99999]))->assertInvalid('team_id');
        $this->assertSame(0, Ticket::query()->count());

        $this->signIn($this->jefe)->post('/tickets', $this->payload(['team_id' => $this->teamB->id]))->assertSessionHasNoErrors();

        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame($this->teamB->id, $ticket->team_id);
        $this->assertSame($this->jefe->id, $ticket->created_by);
    }

    public function test_create_form_shows_the_team_selector_only_to_the_jefe(): void
    {
        $this->signIn($this->jefe)->get('/tickets/create')->assertOk()->assertSee('name="team_id"', false);
        $this->signIn($this->empA1)->get('/tickets/create')->assertOk()->assertDontSee('name="team_id"', false);
    }

    public function test_validation_of_required_fields_and_limits(): void
    {
        $actor = $this->signIn($this->empA1);

        $actor->post('/tickets', [])->assertInvalid(['title', 'description', 'priority']);
        $actor->post('/tickets', $this->payload(['title' => str_repeat('a', 256)]))->assertInvalid('title');
        $actor->post('/tickets', $this->payload(['description' => str_repeat('a', 5001)]))->assertInvalid('description');
        $actor->post('/tickets', $this->payload(['priority' => 'critical']))->assertInvalid('priority');
        $actor->post('/tickets', $this->payload(['title' => ['x']]))->assertInvalid('title');
        $actor->post('/tickets', $this->payload(['category_id' => 99999]))->assertInvalid('category_id');
        $actor->post('/tickets', $this->payload(['category_id' => Category::factory()->inactive()->create()->id]))->assertInvalid('category_id');
        $actor->post('/tickets', $this->payload(['due_date' => 'mañana']))->assertInvalid('due_date');

        $actor->post('/tickets', $this->payload(['title' => str_repeat('a', 255), 'description' => str_repeat('b', 5000)]))->assertValid();
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_due_date_cannot_be_before_today_in_business_time(): void
    {
        // 2026-09-25 03:00 UTC = 24 de septiembre, 22:00 en Cancun.
        Carbon::setTestNow('2026-09-25 03:00:00');
        $actor = $this->signIn($this->empA1);

        $actor->post('/tickets', $this->payload(['due_date' => '2026-09-23']))->assertInvalid('due_date');
        $actor->post('/tickets', $this->payload(['due_date' => '2026-09-24']))->assertValid();
        $actor->post('/tickets', $this->payload(['due_date' => '2026-12-01']))->assertValid();
        $actor->post('/tickets', $this->payload())->assertValid();
    }

    public function test_mass_assignment_cannot_set_system_fields(): void
    {
        $this->signIn($this->empA1)->post('/tickets', $this->payload([
            'id' => 4242,
            'folio' => 'HACK-0001',
            'status' => 'completed',
            'team_id' => $this->teamB->id,
            'created_by' => $this->jefe->id,
            'source' => 'email',
            'completed_at' => '2020-01-01 00:00:00',
            'deleted_at' => '2020-01-01 00:00:00',
        ]))->assertSessionHasNoErrors();

        $ticket = Ticket::query()->firstOrFail();
        $this->assertNotSame(4242, $ticket->id);
        $this->assertNotSame('HACK-0001', $ticket->folio);
        $this->assertSame(TicketStatus::Pending, $ticket->status);
        $this->assertSame($this->teamA->id, $ticket->team_id);
        $this->assertSame($this->empA1->id, $ticket->created_by);
        $this->assertSame(TicketSource::Web, $ticket->source);
        $this->assertNull($ticket->completed_at);
        $this->assertNull($ticket->deleted_at);
    }

    public function test_ticket_model_only_allows_form_fields_in_fill(): void
    {
        $ticket = new Ticket(['title' => 'a', 'status' => 'completed', 'team_id' => 5, 'folio' => 'X', 'created_by' => 9]);

        $this->assertSame(['title' => 'a'], $ticket->getAttributes());
    }

    // --- Editar ------------------------------------------------------------------

    public function test_creator_can_edit_while_pending_but_not_afterwards(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);

        $this->signIn($this->empA1)->get("/tickets/{$ticket->id}/edit")->assertOk();
        $this->signIn($this->empA1)->put("/tickets/{$ticket->id}", $this->payload(['title' => 'Titulo nuevo']))
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('status', 'ticket-updated');

        $this->assertSame('Titulo nuevo', $ticket->fresh()->title);
        $updated = Activity::query()->where('subject_type', 'ticket')->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($this->empA1->id, $updated->causer_id);
        $this->assertSame('Titulo nuevo', $updated->attribute_changes['attributes']['title']);

        $ticket->forceFill(['status' => TicketStatus::InProgress])->save();

        $this->signIn($this->empA1)->get("/tickets/{$ticket->id}/edit")->assertForbidden();
        $this->signIn($this->empA1)->put("/tickets/{$ticket->id}", $this->payload(['title' => 'Otra vez']))->assertForbidden();
        $this->assertSame('Titulo nuevo', $ticket->fresh()->title);
    }

    public function test_non_creator_employee_cannot_edit_even_a_visible_bag_ticket(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);

        $this->signIn($this->empA2)->get("/tickets/{$ticket->id}")->assertOk();
        $this->signIn($this->empA2)->get("/tickets/{$ticket->id}/edit")->assertForbidden();
        $this->signIn($this->empA2)->put("/tickets/{$ticket->id}", $this->payload())->assertForbidden();
    }

    public function test_coordinator_of_the_team_and_jefe_can_edit_in_progress_tickets(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->inProgress()->create();

        $this->signIn($this->coordA)->put("/tickets/{$ticket->id}", $this->payload(['title' => 'Por coordinador']))->assertRedirect();
        $this->assertSame('Por coordinador', $ticket->fresh()->title);

        $this->signIn($this->jefe)->put("/tickets/{$ticket->id}", $this->payload(['title' => 'Por jefe']))->assertRedirect();
        $this->assertSame('Por jefe', $ticket->fresh()->title);
    }

    public function test_final_tickets_cannot_be_edited_by_anyone(): void
    {
        $completed = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->completed()->create();
        $cancelled = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->cancelled()->create();

        foreach ([$completed, $cancelled] as $ticket) {
            $this->signIn($this->jefe)->put("/tickets/{$ticket->id}", $this->payload())->assertForbidden();
            $this->signIn($this->coordA)->get("/tickets/{$ticket->id}/edit")->assertForbidden();
        }
    }

    public function test_editing_keeps_a_past_due_date_and_an_inactive_category_already_on_the_ticket(): void
    {
        $category = Category::factory()->create();
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->overdue()->create(['category_id' => $category->id]);
        $category->update(['active' => false]);
        $pastDate = $ticket->due_date->toDateString();

        $this->signIn($this->coordA)->put("/tickets/{$ticket->id}", $this->payload([
            'category_id' => $category->id,
            'due_date' => $pastDate,
        ]))->assertSessionHasNoErrors();

        // Pero una fecha pasada NUEVA sigue siendo invalida.
        $this->signIn($this->coordA)->put("/tickets/{$ticket->id}", $this->payload(['category_id' => $category->id, 'due_date' => '2001-01-01']))
            ->assertInvalid('due_date');
    }

    public function test_update_cannot_change_system_fields(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);
        $folio = $ticket->folio;

        $this->signIn($this->coordA)->put("/tickets/{$ticket->id}", $this->payload([
            'status' => 'completed',
            'team_id' => $this->teamB->id,
            'created_by' => $this->jefe->id,
            'folio' => 'HACK',
        ]))->assertSessionHasNoErrors();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Pending, $fresh->status);
        $this->assertSame($this->teamA->id, $fresh->team_id);
        $this->assertSame($this->empA1->id, $fresh->created_by);
        $this->assertSame($folio, $fresh->folio);
    }

    // --- Eliminar ----------------------------------------------------------------

    public function test_coordinator_of_the_team_soft_deletes_a_pending_ticket_and_it_is_audited(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->signIn($this->coordA)->delete("/tickets/{$ticket->id}")
            ->assertRedirect(route('tickets.index'))
            ->assertSessionHas('status', 'ticket-deleted');

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
        $activity = Activity::query()->where('subject_type', 'ticket')->where('event', 'deleted')->firstOrFail();
        $this->assertSame($this->coordA->id, $activity->causer_id);
        $this->signIn($this->jefe)->get("/tickets/{$ticket->id}")->assertNotFound();
    }

    public function test_jefe_can_delete_a_cancelled_ticket(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamB)->cancelled()->create();

        $this->signIn($this->jefe)->delete("/tickets/{$ticket->id}")->assertRedirect(route('tickets.index'));

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_tickets_with_work_in_progress_or_completed_cannot_be_deleted(): void
    {
        foreach (['inProgress', 'inReview', 'completed'] as $state) {
            $ticket = Ticket::factory()->forTeam($this->teamA)->{$state}()->create();

            $this->signIn($this->jefe)->from('/tickets')->delete("/tickets/{$ticket->id}")
                ->assertRedirect('/tickets')
                ->assertSessionHas('error', __('tickets.errors.delete_state'));

            $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
        }
    }

    public function test_employees_cannot_delete_even_their_own_ticket(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);

        $this->signIn($this->empA1)->delete("/tickets/{$ticket->id}")->assertForbidden();

        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    // --- Mostrar -------------------------------------------------------------------

    public function test_show_renders_the_ticket_and_escapes_user_content(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1, [
            'title' => '<b>Titulo</b>',
            'description' => '<script>alert("xss")</script>',
        ]);

        $this->signIn($this->empA1)->get("/tickets/{$ticket->id}")
            ->assertOk()
            ->assertSee($ticket->folio)
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertDontSee('<b>Titulo</b>', false)
            ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
    }

    public function test_overdue_flag_is_shown_only_for_open_tickets(): void
    {
        $open = Ticket::factory()->forTeam($this->teamA)->overdue()->create();
        $done = Ticket::factory()->forTeam($this->teamA)->completed()->overdue()->create();

        $this->signIn($this->jefe)->get("/tickets/{$open->id}")->assertSee(__('tickets.show.overdue_hint'));
        $this->signIn($this->jefe)->get("/tickets/{$done->id}")->assertDontSee(__('tickets.show.overdue_hint'));
    }

    public function test_unknown_ticket_returns_404(): void
    {
        $this->signIn($this->jefe)->get('/tickets/999999')->assertNotFound();
    }

    public function test_ticket_creation_is_rate_limited(): void
    {
        config(['tickets.rate_limits.ticket_create_per_hour' => 3]);
        $actor = $this->signIn($this->empA1);

        for ($i = 0; $i < 3; $i++) {
            $actor->post('/tickets', $this->payload())->assertRedirect();
        }

        $actor->post('/tickets', $this->payload())->assertStatus(429);
        $this->assertSame(3, Ticket::query()->count());
    }
}
