<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Models\Activity;
use App\Models\Coordination;
use App\Models\Station;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Equipos, usuarios y actividades se muestran segmentados por edificio / coordinación / equipo (nunca revueltos), y
 * los formularios y filtros ofrecen el selector en cascada Edificio → Coordinación → Equipo.
 */
class CoordinationSegmentationTest extends DatabaseTestCase
{
    private Coordination $coordA;

    private Coordination $coordB;

    private Team $teamA1;

    private Team $teamA2;

    private Team $teamB1;

    protected function setUp(): void
    {
        parent::setUp();

        $stationA = Station::factory()->create(['name' => 'Edificio Alfa']);
        $stationB = Station::factory()->create(['name' => 'Edificio Beta']);
        $this->coordA = Coordination::factory()->create(['station_id' => $stationA->id, 'name' => 'Coord Norte']);
        $this->coordB = Coordination::factory()->create(['station_id' => $stationB->id, 'name' => 'Coord Sur']);
        $this->teamA1 = Team::factory()->create(['coordination_id' => $this->coordA->id, 'name' => 'Equipo A1']);
        $this->teamA2 = Team::factory()->create(['coordination_id' => $this->coordA->id, 'name' => 'Equipo A2']);
        $this->teamB1 = Team::factory()->create(['coordination_id' => $this->coordB->id, 'name' => 'Equipo B1']);
    }

    public function test_teams_index_groups_teams_under_a_heading_per_coordination(): void
    {
        $admin = User::factory()->administrador()->create();

        $response = $this->signIn($admin)->get(route('teams.index'))->assertOk();

        // Un solo encabezado por coordinación, con sus equipos debajo (no intercalados).
        $response->assertSeeInOrder(['Edificio Alfa › Coord Norte', 'Equipo A1', 'Equipo A2', 'Edificio Beta › Coord Sur', 'Equipo B1']);
        $this->assertSame(1, substr_count($response->getContent(), 'scope="colgroup"') - 1);
    }

    public function test_users_index_groups_by_coordination_and_filters_by_it(): void
    {
        $admin = User::factory()->administrador()->create();
        User::factory()->empleado()->inTeams($this->teamB1)->create(['name' => 'Persona Beta']);
        User::factory()->empleado()->inTeams($this->teamA1)->create(['name' => 'Persona Alfa']);

        $this->signIn($admin)->get(route('users.index'))
            ->assertOk()
            ->assertSeeInOrder(['Edificio Alfa › Coord Norte', 'Persona Alfa', 'Edificio Beta › Coord Sur', 'Persona Beta']);

        $this->signIn($admin)->get(route('users.index', ['coordination_id' => $this->coordB->id]))
            ->assertOk()
            ->assertSee('Persona Beta')
            ->assertDontSee('Persona Alfa');
    }

    public function test_ticket_form_offers_the_cascade_with_teams_grouped_by_coordination(): void
    {
        $employee = User::factory()->empleado()->inTeams($this->teamA1)->create();

        $response = $this->signIn($employee)->get(route('tickets.create'))->assertOk();

        $response->assertSee('x-data="teamCascade"', false)
            ->assertSee('<optgroup label="Edificio Alfa › Coord Norte">', false)
            ->assertSee('<optgroup label="Edificio Beta › Coord Sur">', false);

        // Cada equipo cuelga de su coordinación, y la coordinación de su edificio.
        $response->assertSee('value="'.$this->teamB1->id.'" data-parent="'.$this->coordB->id.'"', false)
            ->assertSee('value="'.$this->coordB->id.'" data-parent="'.$this->coordB->station_id.'"', false);
    }

    public function test_activities_index_groups_by_team(): void
    {
        $admin = User::factory()->administrador()->create();
        Activity::factory()->forTeam($this->teamB1)->create(['title' => 'Tarea del equipo B1']);
        Activity::factory()->forTeam($this->teamA1)->create(['title' => 'Tarea del equipo A1']);
        Activity::factory()->forTeam($this->teamA2)->create(['title' => 'Tarea del equipo A2']);

        $this->signIn($admin)->get(route('activities.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'Edificio Alfa › Coord Norte › Equipo A1', 'Tarea del equipo A1',
                'Edificio Alfa › Coord Norte › Equipo A2', 'Tarea del equipo A2',
                'Edificio Beta › Coord Sur › Equipo B1', 'Tarea del equipo B1',
            ]);
    }

    public function test_jefe_only_gets_teams_of_his_coordinations_in_the_selectors(): void
    {
        $jefe = User::factory()->jefeOf($this->coordA)->create();

        $this->signIn($jefe)->get(route('activities.create'))
            ->assertOk()
            ->assertSee('Equipo A1')
            ->assertDontSee('Equipo B1');
    }

    public function test_overdue_purge_deletes_only_open_overdue_work_of_the_managed_teams(): void
    {
        $jefe = User::factory()->jefeOf($this->coordA)->create();

        $overdueTicket = Ticket::factory()->forTeam($this->teamA1)->overdue()->create();
        $overdueActivity = Activity::factory()->forTeam($this->teamA2)->overdue()->create();
        $onTime = Ticket::factory()->forTeam($this->teamA1)->dueOn(now()->addDays(5)->toDateString())->create();
        $completed = Ticket::factory()->forTeam($this->teamA1)->completed()->overdue()->create();
        $template = Activity::factory()->forTeam($this->teamA1)->recurring()->overdue()->create();
        $otherCoordination = Ticket::factory()->forTeam($this->teamB1)->overdue()->create();

        $this->signIn($jefe)->post(route('overdue.purge'))->assertRedirect();

        $this->assertSoftDeleted($overdueTicket);
        $this->assertSoftDeleted($overdueActivity);
        $this->assertNotSoftDeleted($onTime);
        $this->assertNotSoftDeleted($completed);
        $this->assertNotSoftDeleted($template);
        $this->assertNotSoftDeleted($otherCoordination);
    }

    public function test_coordinator_purge_is_limited_to_his_own_team(): void
    {
        $coordinator = User::factory()->coordinador()->inTeams($this->teamA1)->create(['team_id' => $this->teamA1->id]);
        $own = Ticket::factory()->forTeam($this->teamA1)->overdue()->create();
        $sameCoordinationOtherTeam = Ticket::factory()->forTeam($this->teamA2)->overdue()->create();

        $this->signIn($coordinator)->post(route('overdue.purge'))->assertRedirect();

        $this->assertSoftDeleted($own);
        $this->assertNotSoftDeleted($sameCoordinationOtherTeam);
    }

    public function test_employee_cannot_purge_and_does_not_see_the_button(): void
    {
        $employee = User::factory()->empleado()->inTeams($this->teamA1)->create();
        $ticket = Ticket::factory()->forTeam($this->teamA1)->overdue()->create();

        $this->signIn($employee)->post(route('overdue.purge'))->assertForbidden();
        $this->signIn($employee)->get(route('tickets.index'))->assertDontSee(route('overdue.purge'), false);

        $this->assertNotSoftDeleted($ticket);
    }

    public function test_button_shows_for_jefe_only_when_there_is_something_overdue(): void
    {
        $jefe = User::factory()->jefeOf($this->coordA)->create();

        $this->signIn($jefe)->get(route('tickets.index'))->assertDontSee(route('overdue.purge'), false);

        Ticket::factory()->forTeam($this->teamA1)->overdue()->create();

        $this->signIn($jefe)->get(route('tickets.index'))->assertSee(route('overdue.purge'), false);
    }
}
