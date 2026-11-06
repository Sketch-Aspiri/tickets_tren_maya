<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Enums\IncomingEmailStatus;
use App\Models\Category;
use App\Models\Coordination;
use App\Models\IncomingEmail;
use App\Models\Station;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Errores de uso con varias estaciones y coordinaciones: listas revueltas, combinaciones imposibles (categoría o
 * persona de otra coordinación/equipo) y tickets a Soporte del sistema.
 */
class MultiCoordinationUsabilityTest extends DatabaseTestCase
{
    private Coordination $north;

    private Coordination $support;

    private Team $northA;

    private Team $northB;

    private Team $helpdesk;

    protected function setUp(): void
    {
        parent::setUp();

        $field = Station::factory()->create(['name' => 'Edificio Campo']);
        $zonal = Station::factory()->create(['name' => 'Edificio Zonal']);
        $this->north = Coordination::factory()->create(['station_id' => $field->id, 'name' => 'Coord Norte']);
        $this->support = Coordination::factory()->create(['station_id' => $zonal->id, 'name' => 'Soporte del sistema']);
        $this->northA = Team::factory()->create(['coordination_id' => $this->north->id, 'name' => 'Cuadrilla A']);
        $this->northB = Team::factory()->create(['coordination_id' => $this->north->id, 'name' => 'Cuadrilla B']);
        $this->helpdesk = Team::factory()->create(['coordination_id' => $this->support->id, 'name' => 'Mesa de ayuda']);
    }

    public function test_user_page_shows_only_the_teams_of_the_persons_coordination_and_folds_the_rest(): void
    {
        $admin = User::factory()->administrador()->create();
        $employee = User::factory()->empleado()->inTeams($this->northA)->create();

        $this->signIn($admin)->get(route('users.show', $employee))
            ->assertOk()
            ->assertSeeInOrder(['Edificio Campo › Coord Norte', 'Cuadrilla A', 'Cuadrilla B', __('users.show.other_coordinations_teams'), 'Mesa de ayuda']);
    }

    public function test_a_ticket_to_another_coordination_cannot_carry_a_category_of_the_origin(): void
    {
        $employee = User::factory()->empleado()->inTeams($this->northA)->create();
        $northCategory = Category::factory()->create(['coordination_id' => $this->north->id]);
        $general = Category::factory()->create(['coordination_id' => null]);
        $payload = ['title' => 'Falla', 'description' => 'No abre el sistema', 'priority' => 'medium', 'team_id' => $this->helpdesk->id];

        $this->signIn($employee)->post(route('tickets.store'), [...$payload, 'category_id' => $northCategory->id])->assertInvalid('category_id');
        $this->signIn($employee)->post(route('tickets.store'), [...$payload, 'category_id' => $general->id])->assertSessionHasNoErrors();
    }

    public function test_everyone_can_open_a_ticket_to_system_support_with_its_categories_and_still_see_it(): void
    {
        $supportCategory = Category::factory()->create(['coordination_id' => $this->support->id, 'name' => 'Falla del sistema']);
        $payload = ['title' => 'No puedo entrar', 'description' => 'Error al iniciar sesión', 'priority' => 'high', 'team_id' => $this->helpdesk->id, 'category_id' => $supportCategory->id];

        $people = [
            User::factory()->empleado()->inTeams($this->northA)->create(),
            User::factory()->coordinador()->inTeams($this->northA)->create(['team_id' => $this->northA->id]),
            User::factory()->jefeOf($this->north)->create(),
            User::factory()->administrador()->create(),
        ];

        foreach ($people as $person) {
            // El formulario ofrece las categorías de Soporte y el acceso directo preselecciona su coordinación.
            $this->signIn($person)->get(route('tickets.create', ['coordination_id' => $this->support->id]))
                ->assertOk()
                ->assertSee('Falla del sistema')
                ->assertSee('value="'.$this->support->id.'" data-parent="'.$this->support->station_id.'" selected', false);

            $this->signIn($person)->post(route('tickets.store'), $payload)->assertSessionHasNoErrors();
            $ticket = Ticket::query()->where('created_by', $person->id)->latest('id')->firstOrFail();

            $this->assertSame($this->helpdesk->id, $ticket->team_id);
            // Quien lo creó lo sigue abriendo (antes el coordinador recibía 404).
            $this->signIn($person)->get(route('tickets.show', $ticket))->assertOk();
        }
    }

    public function test_temayin_shows_the_support_reminder_bubble(): void
    {
        $employee = User::factory()->empleado()->inTeams($this->northA)->create();

        $this->signIn($employee)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('assistant.bubble.text'))
            ->assertSee(route('tickets.create', ['coordination_id' => $this->support->id]), false);
    }

    public function test_activity_assignees_must_belong_to_the_activity_team(): void
    {
        $jefe = User::factory()->jefeOf($this->north)->create();
        $memberA = User::factory()->empleado()->inTeams($this->northA)->create();
        $memberB = User::factory()->empleado()->inTeams($this->northB)->create();
        $payload = ['title' => 'Revisión', 'description' => 'Revisar vía', 'priority' => 'medium', 'team_id' => $this->northA->id];

        // En el formulario el selector filtra por el equipo elegido.
        $html = $this->signIn($jefe)->get(route('activities.create'))
            ->assertOk()
            ->assertSee('data-filter-by="team"', false)
            ->getContent();
        // Cada persona declara sus equipos (la fábrica `empleado()` ya le da uno propio, por eso puede haber varios).
        $this->assertMatchesRegularExpression('/data-teams="(?:[0-9]+,)*'.$this->northB->id.'(?:,[0-9]+)*"/', $html);

        // Otro equipo de la misma coordinación: rechazado como responsable y como colaborador.
        $this->signIn($jefe)->post(route('activities.store'), [...$payload, 'responsible_id' => $memberB->id])->assertSessionHas('error');
        $this->signIn($jefe)->post(route('activities.store'), [...$payload, 'responsible_id' => $memberA->id, 'collaborator_ids' => [$memberB->id]])->assertSessionHas('error');
        $this->signIn($jefe)->post(route('activities.store'), [...$payload, 'responsible_id' => $memberA->id])->assertSessionHasNoErrors();
    }

    public function test_ticket_assignment_list_only_offers_people_of_the_ticket_coordination(): void
    {
        $admin = User::factory()->administrador()->create();
        User::factory()->empleado()->inTeams($this->northA)->create(['name' => 'Persona Norte']);
        User::factory()->empleado()->inTeams($this->helpdesk)->create(['name' => 'Persona Soporte']);
        $ticket = Ticket::factory()->forTeam($this->helpdesk)->create();

        $this->signIn($admin)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Persona Soporte')
            ->assertDontSee('Persona Norte');
    }

    public function test_tracking_panel_uses_the_station_coordination_team_cascade(): void
    {
        $admin = User::factory()->administrador()->create();

        $this->signIn($admin)->get(route('tracking.index', ['station_id' => $this->support->station_id]))
            ->assertOk()
            ->assertSee('x-data="teamCascade"', false)
            ->assertSee('name="station_id"', false)
            ->assertSee('name="coordination_id"', false)
            ->assertSee('<optgroup label="Edificio Zonal › Soporte del sistema">', false);
    }

    public function test_the_email_conversion_form_works_with_several_teams(): void
    {
        $admin = User::factory()->administrador()->create();
        $email = new IncomingEmail([
            'coordination_id' => $this->north->id,
            'message_id' => 'msg-multi-1',
            'from_email' => 'alguien@example.com',
            'from_name' => 'Alguien',
            'subject' => 'Solicitud',
            'body' => 'Detalle',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        $this->signIn($admin)->get("/incoming-emails/{$email->id}/convert")
            ->assertOk()
            ->assertSee('Cuadrilla A')
            ->assertSee('Cuadrilla B')
            ->assertDontSee('Mesa de ayuda');
    }
}
