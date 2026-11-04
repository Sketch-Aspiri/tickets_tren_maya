<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Enums\IncomingEmailStatus;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Coordination;
use App\Models\IncomingEmail;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DashboardMetricsService;
use App\Support\Dashboard\DashboardFilters;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

/**
 * Aislamiento entre dos coordinaciones de dos estaciones distintas: cada jefe de zona y coordinador ve SOLO lo
 * suyo (404 al cambiar un ID en la URL); el administrador ve todo; y un empleado puede ENVIAR un ticket a un
 * equipo de otra coordinacion.
 */
class CoordinationIsolationTest extends DatabaseTestCase
{
    private Coordination $coordA;

    private Coordination $coordB;

    private Team $teamA;

    private Team $teamB;

    private User $admin;

    private User $bossA;

    private User $bossB;

    private User $coordinatorA;

    private User $coordinatorB;

    private User $employeeA;

    private User $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->coordA = Coordination::factory()->create(['name' => 'Coordinacion Alfa']);
        $this->coordB = Coordination::factory()->create(['name' => 'Coordinacion Beta']);
        $this->assertNotSame($this->coordA->station_id, $this->coordB->station_id);

        $this->teamA = Team::factory()->create(['coordination_id' => $this->coordA->id, 'name' => 'Equipo Alfa']);
        $this->teamB = Team::factory()->create(['coordination_id' => $this->coordB->id, 'name' => 'Equipo Beta']);

        $this->admin = User::factory()->administrador()->create();
        $this->bossA = User::factory()->jefeOf($this->coordA)->create(['name' => 'Jefe Alfa']);
        $this->bossB = User::factory()->jefeOf($this->coordB)->create(['name' => 'Jefe Beta']);
        $this->coordinatorA = User::factory()->coordinador()->inTeams($this->teamA)->create(['name' => 'Coord Alfa']);
        $this->coordinatorB = User::factory()->coordinador()->inTeams($this->teamB)->create(['name' => 'Coord Beta']);
        $this->employeeA = User::factory()->empleado()->inTeams($this->teamA)->create(['name' => 'Emp Alfa']);
        $this->employeeB = User::factory()->empleado()->inTeams($this->teamB)->create(['name' => 'Emp Beta']);
        // Las fabricas coordinador()/empleado() crean un equipo por defecto: se deja solo la pertenencia pedida.
        foreach ([[$this->coordinatorA, $this->teamA], [$this->employeeA, $this->teamA], [$this->coordinatorB, $this->teamB], [$this->employeeB, $this->teamB]] as [$user, $team]) {
            $user->teams()->sync([$team->id]);
        }
    }

    /**
     * @return list<int>
     */
    private function listed(User $actor, string $uri, string $variable): array
    {
        return $this->signIn($actor)->get($uri)->assertOk()->viewData($variable)->getCollection()->pluck('id')->all();
    }

    private function ticketIn(Team $team, ?User $creator = null): Ticket
    {
        return Ticket::factory()->forTeam($team)->createdBy($creator ?? ($team->is($this->teamA) ? $this->employeeA : $this->employeeB))->create();
    }

    private function activityIn(Team $team): Activity
    {
        return Activity::factory()->forTeam($team)->createdBy($team->is($this->teamA) ? $this->coordinatorA : $this->coordinatorB)->create();
    }

    // --- Tickets ---------------------------------------------------------------------------------

    public function test_each_boss_lists_only_the_tickets_of_their_own_coordination(): void
    {
        $ticketA = $this->ticketIn($this->teamA);
        $ticketB = $this->ticketIn($this->teamB);

        $this->assertSame([$ticketA->id], $this->listed($this->bossA, '/tickets', 'tickets'));
        $this->assertSame([$ticketB->id], $this->listed($this->bossB, '/tickets', 'tickets'));
        $this->assertEqualsCanonicalizing([$ticketA->id, $ticketB->id], $this->listed($this->admin, '/tickets', 'tickets'));
    }

    public function test_a_boss_gets_404_for_everything_about_a_ticket_of_another_coordination(): void
    {
        $ticket = $this->ticketIn($this->teamB);

        foreach ([$this->bossA, $this->coordinatorA, $this->employeeA] as $actor) {
            $this->signIn($actor)->get("/tickets/{$ticket->id}")->assertNotFound();
            $this->signIn($actor)->get("/tickets/{$ticket->id}/edit")->assertNotFound();
            $this->signIn($actor)->post("/tickets/{$ticket->id}/comments", ['body' => 'hola'])->assertNotFound();
            $this->signIn($actor)->post("/tickets/{$ticket->id}/transition", ['to' => 'in_progress'])->assertNotFound();
        }

        $this->signIn($this->bossB)->get("/tickets/{$ticket->id}")->assertOk();
        $this->signIn($this->admin)->get("/tickets/{$ticket->id}")->assertOk();
    }

    public function test_attachments_of_another_coordination_are_not_downloadable(): void
    {
        $ticket = $this->ticketIn($this->teamB);
        $attachment = Attachment::factory()->for($ticket, 'attachable')->create(['user_id' => $this->employeeB->id]);
        Storage::disk('local')->put($attachment->path, 'contenido');

        $this->signIn($this->bossA)->get("/attachments/{$attachment->id}")->assertNotFound();
        $this->signIn($this->coordinatorA)->get("/attachments/{$attachment->id}")->assertNotFound();
        $this->signIn($this->bossB)->get("/attachments/{$attachment->id}")->assertStatus(200);
    }

    public function test_ticket_export_never_includes_another_coordination(): void
    {
        $this->ticketIn($this->teamA);
        $other = $this->ticketIn($this->teamB);

        $response = $this->signIn($this->bossA)->get('/tickets/export?format=xlsx')->assertOk();

        $this->assertStringNotContainsString($other->folio, $response->streamedContent());
        $this->assertSame(1, $this->listedCount($this->bossA));
    }

    private function listedCount(User $actor): int
    {
        return count($this->listed($actor, '/tickets', 'tickets'));
    }

    // --- Actividades -----------------------------------------------------------------------------

    public function test_activities_are_isolated_by_coordination(): void
    {
        $activityA = $this->activityIn($this->teamA);
        $activityB = $this->activityIn($this->teamB);

        $this->assertSame([$activityA->id], $this->listed($this->bossA, '/activities', 'activities'));
        $this->assertSame([$activityB->id], $this->listed($this->coordinatorB, '/activities', 'activities'));
        $this->signIn($this->bossA)->get("/activities/{$activityB->id}")->assertNotFound();
        $this->signIn($this->coordinatorA)->get("/activities/{$activityB->id}")->assertNotFound();
        $this->signIn($this->admin)->get("/activities/{$activityB->id}")->assertOk();
    }

    // --- Envio de un ticket a otra coordinacion --------------------------------------------------

    public function test_an_employee_can_send_a_ticket_to_a_team_of_another_coordination(): void
    {
        $this->signIn($this->employeeA)->post('/tickets', [
            'title' => 'Falla en TI',
            'description' => 'Necesito apoyo del otro equipo',
            'priority' => 'medium',
            'team_id' => $this->teamB->id,
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Falla en TI')->firstOrFail();
        $this->assertSame($this->teamB->id, $ticket->team_id);

        // El equipo destino lo ve en su bolsa; el creador sigue pudiendo abrirlo.
        foreach ([$this->bossB, $this->coordinatorB, $this->employeeB, $this->employeeA] as $actor) {
            $this->signIn($actor)->get("/tickets/{$ticket->id}")->assertOk();
        }
        $this->assertContains($ticket->id, $this->listed($this->bossB, '/tickets', 'tickets'));
        $this->assertContains($ticket->id, $this->listed($this->employeeB, '/tickets', 'tickets'));

        // La coordinacion de origen (otro jefe/coordinador) no lo ve ni en el listado ni por ID.
        $this->assertNotContains($ticket->id, $this->listed($this->bossA, '/tickets', 'tickets'));
        $this->signIn($this->bossA)->get("/tickets/{$ticket->id}")->assertNotFound();
        $this->signIn($this->coordinatorA)->get("/tickets/{$ticket->id}")->assertNotFound();
    }

    public function test_a_boss_who_created_a_ticket_for_a_foreign_team_can_still_open_it(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamB)->createdBy($this->bossA)->create();

        $this->signIn($this->bossA)->get("/tickets/{$ticket->id}")->assertOk();
        $this->assertNotContains($ticket->id, $this->listed($this->bossA, '/tickets', 'tickets'));
    }

    // --- Asignacion ------------------------------------------------------------------------------

    public function test_a_boss_cannot_assign_people_of_another_coordination(): void
    {
        $ticket = $this->ticketIn($this->teamA);

        $this->signIn($this->bossA)->from("/tickets/{$ticket->id}")->put("/tickets/{$ticket->id}/assignments", [
            'responsible_id' => $this->employeeB->id,
            'collaborator_ids' => [],
        ])->assertSessionHas('error');

        $this->assertSame(0, $ticket->assignments()->count());

        $this->signIn($this->bossA)->put("/tickets/{$ticket->id}/assignments", [
            'responsible_id' => $this->employeeA->id,
            'collaborator_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $ticket->assignments()->count());
    }

    public function test_assignable_users_of_a_boss_are_limited_to_their_coordination(): void
    {
        $names = $this->signIn($this->bossA)->get('/tickets/'.$this->ticketIn($this->teamA)->id)->assertOk()->getContent();

        $this->assertStringContainsString('Emp Alfa', $names);
        $this->assertStringNotContainsString('Emp Beta', $names);
    }

    // --- Correos entrantes -----------------------------------------------------------------------

    private function emailIn(Coordination $coordination): IncomingEmail
    {
        $email = new IncomingEmail([
            'coordination_id' => $coordination->id,
            'message_id' => 'msg-'.$coordination->id.'-'.uniqid(),
            'from_email' => 'externo@example.com',
            'from_name' => 'Externo',
            'subject' => 'Asunto '.$coordination->name,
            'body' => 'Cuerpo',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        return $email;
    }

    public function test_incoming_emails_are_isolated_by_coordination(): void
    {
        $emailA = $this->emailIn($this->coordA);
        $emailB = $this->emailIn($this->coordB);

        $this->assertSame([$emailA->id], $this->listed($this->bossA, '/incoming-emails', 'emails'));
        $this->assertSame([$emailA->id], $this->listed($this->coordinatorA, '/incoming-emails', 'emails'));
        $this->assertEqualsCanonicalizing([$emailA->id, $emailB->id], $this->listed($this->admin, '/incoming-emails', 'emails'));

        foreach ([$this->bossA, $this->coordinatorA] as $actor) {
            $this->signIn($actor)->get("/incoming-emails/{$emailB->id}")->assertNotFound();
            $this->signIn($actor)->get("/incoming-emails/{$emailB->id}/convert")->assertNotFound();
            $this->signIn($actor)->post("/incoming-emails/{$emailB->id}/discard", ['reason' => 'no'])->assertNotFound();
        }

        $this->assertSame(IncomingEmailStatus::PendingReview, $emailB->fresh()->status);
    }

    // --- Usuarios --------------------------------------------------------------------------------

    public function test_a_boss_manages_only_the_users_of_their_coordination(): void
    {
        $this->signIn($this->bossA)->get("/users/{$this->employeeA->id}")->assertOk();
        $this->signIn($this->bossA)->get("/users/{$this->employeeB->id}")->assertNotFound();
        $this->signIn($this->bossA)->post("/users/{$this->employeeB->id}/deactivate")->assertNotFound();
        $this->signIn($this->bossA)->put("/users/{$this->employeeB->id}", ['role' => 'empleado', 'team_ids' => [$this->teamA->id]])->assertNotFound();
        $this->signIn($this->bossA)->get("/users/{$this->bossB->id}")->assertNotFound();
        $this->signIn($this->bossA)->get("/users/{$this->admin->id}")->assertNotFound();

        $this->assertTrue($this->employeeB->fresh()->isActive());

        $listed = $this->signIn($this->bossA)->get('/users')->assertOk()->viewData('users')->getCollection()->pluck('id')->all();
        $this->assertContains($this->employeeA->id, $listed);
        $this->assertNotContains($this->employeeB->id, $listed);
        $this->assertNotContains($this->bossB->id, $listed);
        $this->assertNotContains($this->admin->id, $listed);
    }

    public function test_pending_accounts_reach_the_boss_of_the_requested_coordination_only(): void
    {
        $forB = User::factory()->pending()->create(['name' => 'Aspirante Beta']);
        $forB->forceFill(['requested_coordination_id' => $this->coordB->id])->save();

        $this->signIn($this->bossA)->get("/users/{$forB->id}")->assertNotFound();
        $this->signIn($this->bossA)->post("/users/{$forB->id}/approve", ['role' => 'empleado', 'team_ids' => [$this->teamA->id]])->assertNotFound();
        $this->signIn($this->bossB)->get("/users/{$forB->id}")->assertOk();
        $this->signIn($this->bossB)->post("/users/{$forB->id}/approve", ['role' => 'empleado', 'team_ids' => [$this->teamB->id]])->assertSessionHasNoErrors();

        $this->assertTrue($forB->fresh()->isActive());
    }

    public function test_a_boss_cannot_approve_a_user_into_a_team_of_another_coordination(): void
    {
        $pending = User::factory()->pending()->create();
        $pending->forceFill(['requested_coordination_id' => $this->coordA->id])->save();

        $this->signIn($this->bossA)->from('/users')->post("/users/{$pending->id}/approve", ['role' => 'empleado', 'team_ids' => [$this->teamB->id]])
            ->assertSessionHas('error');

        $this->assertTrue($pending->fresh()->isPending());
    }

    public function test_only_the_administrator_grants_the_jefe_role_and_coordinations(): void
    {
        $pending = User::factory()->pending()->create();
        $pending->forceFill(['requested_coordination_id' => $this->coordA->id])->save();

        $this->signIn($this->bossA)->from('/users')->post("/users/{$pending->id}/approve", ['role' => 'jefe_zona', 'coordination_ids' => [$this->coordA->id]])
            ->assertSessionHas('error', __('users.errors.admin_only'));

        $this->assertTrue($pending->fresh()->isPending());
    }

    // --- Equipos y categorias --------------------------------------------------------------------

    public function test_a_boss_manages_only_the_teams_of_their_coordination(): void
    {
        $listed = $this->signIn($this->bossA)->get('/teams')->assertOk()->viewData('teams')->getCollection()->pluck('id')->all();
        $this->assertSame([$this->teamA->id], $listed);

        $this->signIn($this->bossA)->get("/teams/{$this->teamB->id}/edit")->assertNotFound();
        $this->signIn($this->bossA)->put("/teams/{$this->teamB->id}", ['coordination_id' => $this->coordB->id, 'name' => 'Hackeado'])->assertNotFound();
        $this->signIn($this->bossA)->delete("/teams/{$this->teamB->id}")->assertNotFound();
        $this->assertSame('Equipo Beta', $this->teamB->fresh()->name);
    }

    public function test_a_boss_cannot_create_a_team_in_another_coordination_but_can_in_their_own(): void
    {
        $this->signIn($this->bossA)->post('/teams', ['coordination_id' => $this->coordB->id, 'name' => 'Intruso'])->assertInvalid(['coordination_id']);
        $this->signIn($this->bossA)->post('/teams', ['coordination_id' => $this->coordA->id, 'name' => 'Propio'])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('teams', ['name' => 'Intruso']);
        $this->assertDatabaseHas('teams', ['name' => 'Propio', 'coordination_id' => $this->coordA->id]);
    }

    public function test_a_boss_cannot_move_their_team_to_another_coordination(): void
    {
        $this->signIn($this->bossA)->put("/teams/{$this->teamA->id}", ['coordination_id' => $this->coordB->id, 'name' => 'Equipo Alfa'])
            ->assertInvalid(['coordination_id']);

        $this->assertSame($this->coordA->id, $this->teamA->fresh()->coordination_id);
    }

    public function test_categories_global_ones_belong_to_the_administrator_and_scoped_ones_to_their_boss(): void
    {
        $global = Category::factory()->create(['name' => 'Global']);
        $ofA = Category::factory()->create(['name' => 'Cat Alfa', 'coordination_id' => $this->coordA->id]);
        $ofB = Category::factory()->create(['name' => 'Cat Beta', 'coordination_id' => $this->coordB->id]);

        $listed = $this->signIn($this->bossA)->get('/categories')->assertOk()->viewData('categories')->getCollection()->pluck('id')->all();
        $this->assertSame([$ofA->id], $listed);

        $this->signIn($this->bossA)->get("/categories/{$ofA->id}/edit")->assertOk();
        $this->signIn($this->bossA)->get("/categories/{$ofB->id}/edit")->assertNotFound();
        $this->signIn($this->bossA)->get("/categories/{$global->id}/edit")->assertNotFound();
        $this->signIn($this->bossA)->delete("/categories/{$ofB->id}")->assertNotFound();

        $this->signIn($this->admin)->get("/categories/{$global->id}/edit")->assertOk();
    }

    public function test_a_boss_must_pick_one_of_their_coordinations_for_a_new_category(): void
    {
        $this->signIn($this->bossA)->post('/categories', ['name' => 'Sin coord'])->assertInvalid(['coordination_id']);
        $this->signIn($this->bossA)->post('/categories', ['name' => 'Ajena', 'coordination_id' => $this->coordB->id])->assertInvalid(['coordination_id']);
        $this->signIn($this->bossA)->post('/categories', ['name' => 'Propia', 'coordination_id' => $this->coordA->id])->assertSessionHasNoErrors();
        $this->signIn($this->admin)->post('/categories', ['name' => 'Para todos'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Propia', 'coordination_id' => $this->coordA->id]);
        $this->assertDatabaseHas('categories', ['name' => 'Para todos', 'coordination_id' => null]);
    }

    public function test_the_same_category_name_is_allowed_in_different_coordinations(): void
    {
        Category::factory()->create(['name' => 'Soporte', 'coordination_id' => $this->coordB->id]);

        $this->signIn($this->bossA)->post('/categories', ['name' => 'Soporte', 'coordination_id' => $this->coordA->id])->assertSessionHasNoErrors();
        $this->signIn($this->bossA)->post('/categories', ['name' => 'Soporte', 'coordination_id' => $this->coordA->id])->assertInvalid(['name']);
    }

    public function test_the_ticket_form_offers_global_and_own_categories_only(): void
    {
        Category::factory()->create(['name' => 'Cat Global']);
        Category::factory()->create(['name' => 'Cat Alfa', 'coordination_id' => $this->coordA->id]);
        Category::factory()->create(['name' => 'Cat Beta', 'coordination_id' => $this->coordB->id]);

        $html = $this->signIn($this->employeeA)->get('/tickets/create')->assertOk()->getContent();

        $this->assertStringContainsString('Cat Global', $html);
        $this->assertStringContainsString('Cat Alfa', $html);
        $this->assertStringNotContainsString('Cat Beta', $html);
    }

    // --- Panel de seguimiento --------------------------------------------------------------------

    public function test_the_tracking_panel_is_scoped_per_boss_and_rejects_foreign_filters(): void
    {
        $this->ticketIn($this->teamA);
        $this->ticketIn($this->teamA);
        $this->ticketIn($this->teamB);

        $a = $this->signIn($this->bossA)->get('/tracking')->assertOk()->viewData('report')['cards']['open']['total'];
        $b = $this->signIn($this->bossB)->get('/tracking')->assertOk()->viewData('report')['cards']['open']['total'];
        $all = $this->signIn($this->admin)->get('/tracking')->assertOk()->viewData('report')['cards']['open']['total'];

        $this->assertSame([2, 1, 3], [$a, $b, $all]);
        $this->signIn($this->bossA)->get('/tracking?team_id='.$this->teamB->id)->assertInvalid(['team_id']);
        $this->signIn($this->bossA)->get('/tracking?user_id='.$this->employeeB->id)->assertInvalid(['user_id']);
    }

    public function test_the_dashboard_cache_key_differs_between_bosses(): void
    {
        $service = app(DashboardMetricsService::class);
        $filters = DashboardFilters::fromValidated([]);

        $this->assertNotSame($service->cacheKey($this->bossA, $filters), $service->cacheKey($this->bossB, $filters));
        $this->assertNotSame($service->cacheKey($this->bossA, $filters), $service->cacheKey($this->admin, $filters));
    }

    // --- Pendientes ------------------------------------------------------------------------------

    public function test_the_team_view_of_pending_work_for_a_boss_stays_inside_their_coordination(): void
    {
        $mine = $this->ticketIn($this->teamA);
        $theirs = $this->ticketIn($this->teamB);

        $ids = $this->listed($this->bossA, '/tickets/pending?scope=team', 'tickets');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_a_boss_without_coordinations_sees_nothing(): void
    {
        $lonely = User::factory()->jefeOf()->create();
        $this->ticketIn($this->teamA);

        $this->assertSame([], $this->listed($lonely, '/tickets', 'tickets'));
        $this->assertSame([], $this->listed($lonely, '/activities', 'activities'));
    }

    // --- Bitacora --------------------------------------------------------------------------------

    public function test_the_audit_log_is_scoped_to_the_coordinations_of_each_boss(): void
    {
        $ticketA = $this->ticketIn($this->teamA);
        $ticketB = $this->ticketIn($this->teamB);
        \Spatie\Activitylog\Models\Activity::query()->delete();

        activity('tickets')->performedOn($ticketA)->causedBy($this->employeeA)->event('commented')->log('commented');
        activity('tickets')->performedOn($ticketB)->causedBy($this->employeeB)->event('commented')->log('commented');
        activity('system')->event('boot')->log('boot');

        $entries = fn (User $viewer): array => $this->signIn($viewer)->get('/audit-log')->assertOk()
            ->viewData('entries')->getCollection()->map(fn ($entry) => [$entry->subject_id, $entry->coordination_id])->all();

        $this->assertSame([[$ticketA->id, $this->coordA->id]], $entries($this->bossA));
        $this->assertSame([[$ticketB->id, $this->coordB->id]], $entries($this->bossB));
        $this->assertCount(3, $entries($this->admin), 'el administrador ve toda la bitacora, incluido lo del sistema');
    }

    public function test_audit_entries_get_the_coordination_of_their_subject(): void
    {
        \Spatie\Activitylog\Models\Activity::query()->delete();
        $category = Category::factory()->create(['coordination_id' => $this->coordB->id]);
        $global = Category::factory()->create();
        $this->teamA->update(['name' => 'Equipo Alfa Renombrado']);

        $coordinationOf = fn ($subject) => \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->value('coordination_id');

        $this->assertSame($this->coordB->id, $coordinationOf($category));
        $this->assertNull($coordinationOf($global));
        $this->assertSame($this->coordA->id, $coordinationOf($this->teamA));
    }

    // --- Panel: filtros de estacion y coordinacion ---------------------------------------------

    public function test_the_administrator_can_filter_the_panel_by_station_and_coordination(): void
    {
        $this->ticketIn($this->teamA);
        $this->ticketIn($this->teamA);
        $this->ticketIn($this->teamB);

        $open = fn (string $query): int => $this->signIn($this->admin)->get('/tracking?'.$query)->assertOk()->viewData('report')['cards']['open']['total'];

        $this->assertSame(2, $open('coordination_id='.$this->coordA->id));
        $this->assertSame(1, $open('station_id='.$this->coordB->station_id));
        $this->assertSame(0, $open('station_id='.$this->coordA->station_id.'&coordination_id='.$this->coordB->id));
    }

    public function test_the_panel_by_coordination_table_appears_only_with_several(): void
    {
        $this->ticketIn($this->teamA);
        $this->ticketIn($this->teamB);

        $adminRows = $this->signIn($this->admin)->get('/tracking')->assertOk()->viewData('report')['by_coordination'];
        $bossRows = $this->signIn($this->bossA)->get('/tracking')->assertOk()->viewData('report')['by_coordination'];

        $this->assertCount(2, $adminRows);
        $this->assertSame([], $bossRows);
    }

    public function test_a_boss_cannot_filter_the_panel_by_a_foreign_station_or_coordination(): void
    {
        $this->signIn($this->bossA)->get('/tracking?coordination_id='.$this->coordB->id)->assertInvalid(['coordination_id']);
        $this->signIn($this->bossA)->get('/tracking?station_id='.$this->coordB->station_id)->assertInvalid(['station_id']);
        $this->signIn($this->bossA)->get('/tracking?coordination_id='.$this->coordA->id)->assertOk();
    }
}
