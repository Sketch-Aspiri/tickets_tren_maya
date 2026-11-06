<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Models\Activity;
use App\Models\Coordination;
use App\Models\Station;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketArrivedInPool;
use Illuminate\Support\Facades\Notification;
use Tests\DatabaseTestCase;

/**
 * Segunda ronda multi-coordinación: aviso al equipo destino, "Enviados por mí" y filtros por edificio/coordinación.
 */
class MultiCoordinationRoundTwoTest extends DatabaseTestCase
{
    private Coordination $north;

    private Coordination $support;

    private Team $northTeam;

    private Team $helpdesk;

    protected function setUp(): void
    {
        parent::setUp();

        $field = Station::factory()->create(['name' => 'Edificio Campo']);
        $zonal = Station::factory()->create(['name' => 'Edificio Zonal']);
        $this->north = Coordination::factory()->create(['station_id' => $field->id, 'name' => 'Coord Norte']);
        $this->support = Coordination::factory()->create(['station_id' => $zonal->id, 'name' => 'Soporte del sistema']);
        $this->northTeam = Team::factory()->create(['coordination_id' => $this->north->id, 'name' => 'Cuadrilla']);
        $this->helpdesk = Team::factory()->create(['coordination_id' => $this->support->id, 'name' => 'Mesa de ayuda']);
    }

    public function test_the_destination_coordinator_and_bosses_are_notified_of_a_new_ticket_in_the_pool(): void
    {
        Notification::fake();
        $supportBoss = User::factory()->jefeOf($this->support)->create();
        $supportCoordinator = User::factory()->coordinador()->inTeams($this->helpdesk)->create(['team_id' => $this->helpdesk->id]);
        $this->helpdesk->forceFill(['coordinator_id' => $supportCoordinator->id])->save();
        $northBoss = User::factory()->jefeOf($this->north)->create();
        $sender = User::factory()->empleado()->inTeams($this->northTeam)->create();

        $this->signIn($sender)->post(route('tickets.store'), [
            'title' => 'No puedo entrar', 'description' => 'Error', 'priority' => 'high', 'team_id' => $this->helpdesk->id,
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo([$supportBoss, $supportCoordinator], TicketArrivedInPool::class);
        Notification::assertNotSentTo([$sender, $northBoss], TicketArrivedInPool::class);
    }

    public function test_sent_by_me_lists_the_tickets_sent_to_other_coordinations(): void
    {
        $coordinator = User::factory()->coordinador()->inTeams($this->northTeam)->create(['team_id' => $this->northTeam->id]);
        $sent = Ticket::factory()->forTeam($this->helpdesk)->createdBy($coordinator)->create(['title' => 'Enviado a soporte']);
        Ticket::factory()->forTeam($this->northTeam)->create(['title' => 'Del propio equipo']);

        // El listado normal muestra solo su equipo (decisión 60)...
        $this->signIn($coordinator)->get(route('tickets.index'))->assertOk()->assertDontSee('Enviado a soporte')->assertSee('Del propio equipo');

        // ...y "Enviados por mí" muestra lo que creó, vaya al equipo que vaya.
        $this->signIn($coordinator)->get(route('tickets.index', ['sent' => 1]))
            ->assertOk()
            ->assertSee($sent->folio)
            ->assertDontSee('Del propio equipo');
    }

    public function test_listings_can_be_filtered_by_station_and_coordination(): void
    {
        $admin = User::factory()->administrador()->create();
        Ticket::factory()->forTeam($this->northTeam)->create(['title' => 'Ticket de campo']);
        Ticket::factory()->forTeam($this->helpdesk)->create(['title' => 'Ticket zonal']);
        Activity::factory()->forTeam($this->northTeam)->create(['title' => 'Actividad de campo']);
        Activity::factory()->forTeam($this->helpdesk)->create(['title' => 'Actividad zonal']);

        $this->signIn($admin)->get(route('tickets.index', ['coordination_id' => $this->support->id]))
            ->assertOk()->assertSee('Ticket zonal')->assertDontSee('Ticket de campo');
        $this->signIn($admin)->get(route('tickets.index', ['station_id' => $this->north->station_id]))
            ->assertOk()->assertSee('Ticket de campo')->assertDontSee('Ticket zonal');
        $this->signIn($admin)->get(route('activities.index', ['coordination_id' => $this->north->id]))
            ->assertOk()->assertSee('Actividad de campo')->assertDontSee('Actividad zonal');
    }

    public function test_a_coordination_filter_never_widens_the_scope(): void
    {
        $jefe = User::factory()->jefeOf($this->north)->create();
        Ticket::factory()->forTeam($this->helpdesk)->create(['title' => 'Ticket ajeno']);

        $this->signIn($jefe)->get(route('tickets.index', ['coordination_id' => $this->support->id]))
            ->assertOk()->assertDontSee('Ticket ajeno');
    }
}
