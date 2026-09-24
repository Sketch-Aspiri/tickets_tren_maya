<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class TicketListingTest extends DatabaseTestCase
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
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function listedIds(User $actor, array $query = [], string $uri = '/tickets'): array
    {
        $response = $this->signIn($actor)->get($uri.'?'.http_build_query($query))->assertOk();

        return $response->viewData('tickets')->getCollection()->pluck('id')->all();
    }

    // --- Alcance por rol -------------------------------------------------------------------------------

    public function test_listing_scope_by_role(): void
    {
        $bagA = $this->makeTicket($this->teamA, $this->coordA);
        $createdByEmp = $this->makeTicket($this->teamA, $this->empA1);
        $assignedToEmp = Ticket::factory()->forTeam($this->teamB)->createdBy($this->coordB)->assignedTo($this->empA1)->create();
        $assignedToPeer = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->assignedTo($this->empA2)->create();
        $teamB = $this->makeTicket($this->teamB, $this->empB1);

        $this->assertEqualsCanonicalizing(
            [$bagA->id, $createdByEmp->id, $assignedToEmp->id, $assignedToPeer->id, $teamB->id],
            $this->listedIds($this->jefe),
        );
        $this->assertEqualsCanonicalizing([$bagA->id, $createdByEmp->id, $assignedToPeer->id], $this->listedIds($this->coordA));
        $this->assertEqualsCanonicalizing([$bagA->id, $createdByEmp->id, $assignedToEmp->id], $this->listedIds($this->empA1));
        $this->assertEqualsCanonicalizing([$bagA->id, $createdByEmp->id, $assignedToPeer->id], $this->listedIds($this->empA2), 'A2 ve la bolsa y lo suyo; el ticket de A1 esta en bolsa');
        $this->assertEqualsCanonicalizing([$teamB->id], $this->listedIds($this->empB1));
    }

    public function test_filters_can_never_widen_the_scope(): void
    {
        $ticketB = $this->makeTicket($this->teamB, $this->empB1);
        $ticketA = $this->makeTicket($this->teamA, $this->empA1);

        $this->assertSame([], $this->listedIds($this->coordA, ['team_id' => $this->teamB->id]));
        $this->assertSame([], $this->listedIds($this->empA1, ['team_id' => $this->teamB->id]));
        $this->assertSame([$ticketA->id], $this->listedIds($this->empA1, ['team_id' => $this->teamA->id]));
        $this->assertSame([$ticketB->id], $this->listedIds($this->jefe, ['team_id' => $this->teamB->id]));
    }

    public function test_soft_deleted_tickets_are_not_listed(): void
    {
        $ticket = $this->makeTicket($this->teamA);
        $ticket->delete();

        $this->assertSame([], $this->listedIds($this->jefe));
    }

    // --- Filtros ---------------------------------------------------------------------------------------------

    public function test_filter_by_status_priority_and_category(): void
    {
        $category = Category::factory()->create();
        $a = Ticket::factory()->forTeam($this->teamA)->inProgress()->priority(Priority::Urgent)->create(['category_id' => $category->id]);
        $b = Ticket::factory()->forTeam($this->teamA)->inProgress()->priority(Priority::Low)->create();
        $c = Ticket::factory()->forTeam($this->teamA)->completed()->priority(Priority::Urgent)->create();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->listedIds($this->jefe, ['status' => 'in_progress']));
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $this->listedIds($this->jefe, ['priority' => 'urgent']));
        $this->assertSame([$a->id], $this->listedIds($this->jefe, ['status' => 'in_progress', 'priority' => 'urgent']));
        $this->assertSame([$a->id], $this->listedIds($this->jefe, ['category_id' => $category->id]));
    }

    public function test_filter_by_responsible_only_matches_the_responsible_role(): void
    {
        $asResponsible = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA1)->create();
        $asCollaborator = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA1, AssignmentRole::Colaborador)->create();
        Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA2)->create();

        $this->assertSame([$asResponsible->id], $this->listedIds($this->coordA, ['responsible_id' => $this->empA1->id]));
        $this->assertNotContains($asCollaborator->id, $this->listedIds($this->coordA, ['responsible_id' => $this->empA1->id]));
    }

    public function test_filter_overdue_and_unassigned(): void
    {
        Carbon::setTestNow('2026-09-24 12:00:00');
        $overdue = Ticket::factory()->forTeam($this->teamA)->dueOn('2026-09-01')->create();
        $overdueAssigned = Ticket::factory()->forTeam($this->teamA)->dueOn('2026-09-01')->assignedTo($this->empA1)->create();
        $future = Ticket::factory()->forTeam($this->teamA)->dueOn('2026-12-01')->create();
        Ticket::factory()->forTeam($this->teamA)->completed()->dueOn('2026-01-01')->create();

        $this->assertEqualsCanonicalizing([$overdue->id, $overdueAssigned->id], $this->listedIds($this->jefe, ['overdue' => 1]));
        $this->assertEqualsCanonicalizing([$overdue->id, $future->id, $this->completedId()], $this->listedIds($this->jefe, ['unassigned' => 1]));
        $this->assertSame([$overdue->id], $this->listedIds($this->jefe, ['overdue' => 1, 'unassigned' => 1]));
    }

    private function completedId(): int
    {
        return (int) Ticket::query()->where('status', TicketStatus::Completed->value)->value('id');
    }

    public function test_search_matches_title_and_folio_and_escapes_like_wildcards(): void
    {
        $percent = Ticket::factory()->forTeam($this->teamA)->create(['title' => 'Descuento 50% total', 'folio' => 'TM-2026-0100']);
        $underscore = Ticket::factory()->forTeam($this->teamA)->create(['title' => 'archivo_final', 'folio' => 'TM-2026-0101']);
        $plain = Ticket::factory()->forTeam($this->teamA)->create(['title' => 'Descuentos varios 500 total', 'folio' => 'TM-2026-0102']);
        $bang = Ticket::factory()->forTeam($this->teamA)->create(['title' => 'Urgente! aviso', 'folio' => 'TM-2026-0103']);

        $this->assertSame([$percent->id], $this->listedIds($this->jefe, ['q' => '50%']));
        $this->assertSame([$underscore->id], $this->listedIds($this->jefe, ['q' => 'o_f']), '"_" no actua como comodin');
        $this->assertSame([], $this->listedIds($this->jefe, ['q' => 'archivoXfinal']));
        $this->assertSame([$plain->id], $this->listedIds($this->jefe, ['q' => 'TM-2026-0102']));
        $this->assertSame([$bang->id], $this->listedIds($this->jefe, ['q' => 'Urgente!']));
        $this->assertEqualsCanonicalizing([$percent->id, $plain->id], $this->listedIds($this->jefe, ['q' => 'Descuento']));
    }

    public function test_search_input_is_bound_not_interpolated(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->create(['title' => 'Normal']);

        $this->assertSame([], $this->listedIds($this->jefe, ['q' => "' OR 1=1 --"]));
        $this->assertSame([], $this->listedIds($this->jefe, ['q' => "'; DROP TABLE tickets; --"]));
        $this->assertSame([$ticket->id], $this->listedIds($this->jefe, ['q' => 'Normal']));
        $this->assertSame(1, Ticket::query()->count());
    }

    // --- Orden -------------------------------------------------------------------------------------------------

    public function test_sorting_by_priority_uses_business_order_not_alphabetical(): void
    {
        $low = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Low)->create();
        $urgent = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Urgent)->create();
        $medium = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Medium)->create();
        $high = Ticket::factory()->forTeam($this->teamA)->priority(Priority::High)->create();

        $this->assertSame([$urgent->id, $high->id, $medium->id, $low->id], $this->listedIds($this->jefe, ['sort' => 'priority', 'direction' => 'desc']));
        $this->assertSame([$low->id, $medium->id, $high->id, $urgent->id], $this->listedIds($this->jefe, ['sort' => 'priority', 'direction' => 'asc']));
    }

    public function test_sorting_by_due_date_puts_tickets_without_date_last_in_both_directions(): void
    {
        $late = Ticket::factory()->forTeam($this->teamA)->dueOn('2030-05-01')->create();
        $none = Ticket::factory()->forTeam($this->teamA)->create();
        $soon = Ticket::factory()->forTeam($this->teamA)->dueOn('2030-01-01')->create();

        $this->assertSame([$soon->id, $late->id, $none->id], $this->listedIds($this->jefe, ['sort' => 'due_date', 'direction' => 'asc']));
        $this->assertSame([$late->id, $soon->id, $none->id], $this->listedIds($this->jefe, ['sort' => 'due_date', 'direction' => 'desc']));
    }

    public function test_default_order_is_newest_first_and_other_sort_columns_work(): void
    {
        $first = Ticket::factory()->forTeam($this->teamA)->create(['title' => 'Bravo']);
        $second = Ticket::factory()->forTeam($this->teamA)->inProgress()->create(['title' => 'Alfa']);

        $this->assertSame([$second->id, $first->id], $this->listedIds($this->jefe));
        $this->assertSame([$second->id, $first->id], $this->listedIds($this->jefe, ['sort' => 'title', 'direction' => 'asc']));
        $this->assertSame([$first->id, $second->id], $this->listedIds($this->jefe, ['sort' => 'status', 'direction' => 'asc']));
        $this->assertSame([$first->id, $second->id], $this->listedIds($this->jefe, ['sort' => 'folio', 'direction' => 'asc']));
    }

    // --- Validacion de parametros ---------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'unknown status' => [['status' => 'vencido'], 'status'],
            'unknown priority' => [['priority' => 'critical'], 'priority'],
            'category not found' => [['category_id' => 99999], 'category_id'],
            'category not int' => [['category_id' => 'abc'], 'category_id'],
            'responsible not found' => [['responsible_id' => 99999], 'responsible_id'],
            'team not found' => [['team_id' => 99999], 'team_id'],
            'overdue garbage' => [['overdue' => 'maybe'], 'overdue'],
            'search too long' => [['q' => str_repeat('a', 101)], 'q'],
            'search array' => [['q' => ['a']], 'q'],
            'sort not whitelisted' => [['sort' => 'id; DROP TABLE tickets'], 'sort'],
            'sort column injection' => [['sort' => 'title desc, (select 1)'], 'sort'],
            'direction garbage' => [['direction' => 'sideways'], 'direction'],
            'page zero' => [['page' => 0], 'page'],
            'page text' => [['page' => 'x'], 'page'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidQueries')]
    public function test_invalid_query_parameters_are_rejected(array $query, string $field): void
    {
        $this->signIn($this->jefe)->get('/tickets?'.http_build_query($query))->assertInvalid($field);
    }

    // --- Paginacion y N+1 ------------------------------------------------------------------------------------------------

    public function test_listing_is_paginated_and_never_loads_everything(): void
    {
        Ticket::factory()->count(20)->forTeam($this->teamA)->create();

        $response = $this->signIn($this->jefe)->get('/tickets')->assertOk();
        $paginator = $response->viewData('tickets');

        $this->assertSame(15, $paginator->count());
        $this->assertSame(20, $paginator->total());
        $this->assertSame(2, $paginator->lastPage());
        $this->assertSame(5, count($this->listedIds($this->jefe, ['page' => 2])));
    }

    public function test_pagination_links_keep_the_active_filters(): void
    {
        Ticket::factory()->count(20)->forTeam($this->teamA)->priority(Priority::High)->create();

        $this->signIn($this->jefe)->get('/tickets?priority=high')->assertOk()->assertSee('priority=high', false);
    }

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
                Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)
                    ->create(['category_id' => Category::factory()->create()->id])
                    ->assignments()->create(['user_id' => $this->empA1->id, 'role' => AssignmentRole::Responsable]);
            }
        };

        $seed(2);
        foreach (['/tickets', '/tickets/pending'] as $uri) {
            foreach ([$this->jefe, $this->coordA, $this->empA1] as $actor) {
                $this->queryCount($actor, $uri); // calienta
            }
        }
        $small = [];
        foreach (['/tickets', '/tickets/pending'] as $uri) {
            foreach ([$this->jefe, $this->coordA, $this->empA1] as $actor) {
                $small[$uri.$actor->id] = $this->queryCount($actor, $uri);
            }
        }

        $seed(10);
        foreach (['/tickets', '/tickets/pending'] as $uri) {
            foreach ([$this->jefe, $this->coordA, $this->empA1] as $actor) {
                $this->assertSame($small[$uri.$actor->id], $this->queryCount($actor, $uri), "N+1 en {$uri} para {$actor->name}");
            }
        }
    }

    public function test_show_page_does_not_grow_queries_with_comments_and_history(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->assignedTo($this->empA1)->create();
        $comment = fn () => $ticket->comments()->create(['body' => 'x', 'user_id' => User::factory()->empleado()->create()->id]);

        foreach (range(1, 2) as $ignored) {
            $comment();
            $ticket->statusHistories()->create(['from_status' => 'pending', 'to_status' => 'in_progress', 'user_id' => $this->coordA->id]);
        }
        $this->queryCount($this->coordA, "/tickets/{$ticket->id}");
        $small = $this->queryCount($this->coordA, "/tickets/{$ticket->id}");

        foreach (range(1, 8) as $ignored) {
            $comment();
            $ticket->statusHistories()->create(['from_status' => 'pending', 'to_status' => 'in_progress', 'user_id' => $this->coordA->id]);
        }

        $this->assertSame($small, $this->queryCount($this->coordA, "/tickets/{$ticket->id}"));
    }

    // --- Mis pendientes ------------------------------------------------------------------------------------------------------------

    public function test_pending_shows_only_open_work_assigned_to_me_ordered_by_due_date_then_priority(): void
    {
        $noDate = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Urgent)->assignedTo($this->empA1)->create();
        $lateLow = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Low)->dueOn('2030-03-01')->assignedTo($this->empA1)->create();
        $soonLow = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Low)->dueOn('2030-01-01')->assignedTo($this->empA1)->create();
        $soonUrgent = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Urgent)->dueOn('2030-01-01')->assignedTo($this->empA1)->create();
        $collab = Ticket::factory()->forTeam($this->teamA)->priority(Priority::Medium)->dueOn('2030-02-01')->assignedTo($this->empA1, AssignmentRole::Colaborador)->create();

        // Excluidos: finales, de otra persona, bolsa, creados por mi pero sin asignar.
        Ticket::factory()->forTeam($this->teamA)->completed()->assignedTo($this->empA1)->create();
        Ticket::factory()->forTeam($this->teamA)->cancelled()->assignedTo($this->empA1)->create();
        Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA2)->create();
        Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();
        Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();

        $this->assertSame(
            [$soonUrgent->id, $soonLow->id, $collab->id, $lateLow->id, $noDate->id],
            $this->listedIds($this->empA1, [], '/tickets/pending'),
        );
    }

    public function test_pending_includes_in_review_and_in_progress_but_uses_scope(): void
    {
        $inReview = Ticket::factory()->forTeam($this->teamA)->inReview()->assignedTo($this->coordA)->create();
        $outsideScope = Ticket::factory()->forTeam($this->teamB)->assignedTo($this->empA1)->create();

        $this->assertSame([$inReview->id], $this->listedIds($this->coordA, [], '/tickets/pending'));
        $this->assertSame([$outsideScope->id], $this->listedIds($this->empA1, [], '/tickets/pending'), 'lo asignado a mi siempre es visible');
    }

    public function test_pending_page_shows_an_empty_message_and_is_paginated(): void
    {
        $this->signIn($this->empA1)->get('/tickets/pending')->assertOk()->assertSee(__('tickets.pending_empty'));

        Ticket::factory()->count(16)->forTeam($this->teamA)->assignedTo($this->empA1)->create();

        $this->assertSame(15, count($this->listedIds($this->empA1, [], '/tickets/pending')));
    }

    public function test_pending_is_not_swallowed_by_the_ticket_show_route(): void
    {
        $this->signIn($this->empA1)->get('/tickets/pending')->assertOk()->assertViewIs('tickets.pending');
    }

    // --- Vista -----------------------------------------------------------------------------------------------------------------------

    public function test_index_renders_badges_overdue_and_escaped_titles(): void
    {
        Ticket::factory()->forTeam($this->teamA)->overdue()->inReview()->create(['title' => '<i>peligroso</i>']);

        $this->signIn($this->jefe)->get('/tickets')
            ->assertOk()
            ->assertSee(TicketStatus::InReview->label())
            ->assertSee(__('tickets.show.overdue'))
            ->assertDontSee('<i>peligroso</i>', false)
            ->assertSee('&lt;i&gt;peligroso&lt;/i&gt;', false);
    }

    public function test_only_the_jefe_gets_the_team_filter_and_column(): void
    {
        $this->makeTicket($this->teamA);

        $this->signIn($this->jefe)->get('/tickets')->assertSee('name="team_id"', false);
        $this->signIn($this->coordA)->get('/tickets')->assertDontSee('name="team_id"', false);
    }

    public function test_sidebar_shows_ticket_links_to_every_role(): void
    {
        foreach ([$this->jefe, $this->coordA, $this->empA1] as $actor) {
            $this->signIn($actor)->get('/dashboard')
                ->assertSee(route('tickets.index'), false)
                ->assertSee(route('tickets.pending'), false);
        }
    }
}
