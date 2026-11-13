<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Coordination;
use App\Models\Team;
use App\Models\User;
use App\Notifications\WorkItemAssigned;
use App\Services\UserService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Actividades que pide el Dashboard por el puente (fase 4, decision 92): firma del POST, equipo destino decidido
 * por Tickets (id o nombre exacto; sin equipo no se crea nada), responsable = coordinador del equipo, usuario de
 * sistema que no puede iniciar sesion, idempotencia por `ref` y textos tratados como dato no confiable.
 */
class EcosystemActivityTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private const FROM_DASHBOARD = 'secreto-dashboard-a-tickets-de-prueba-0123456789';

    private const PATH = '/interno/actividades';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        config([
            'ecosistema.puente.secreto_entrada' => self::FROM_DASHBOARD,
            'ecosistema.puente.ips' => [],
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.actividades.estaciones' => [],
            'ecosistema.actividades.eventos.insumos_observada.equipo' => $this->teamA->id,
            'ecosistema.actividades.eventos.factura_cancelada.equipo' => $this->teamB->name,
            'ecosistema.actividades.eventos.asistencia_pendiente.equipo' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $body, string $system = 'dashboard', string $secret = self::FROM_DASHBOARD): array
    {
        $date = now()->getTimestamp();
        $nonce = Str::random(40);
        $canonical = implode("\n", ['POST', self::PATH, '', $system, (string) $date, $nonce, hash('sha256', $body)]);

        return [
            'X-Puente-Sistema' => $system,
            'X-Puente-Fecha' => (string) $date,
            'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonical, $secret),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'evento' => 'insumos_observada',
            'estacion' => '23',
            'ref' => 'dashboard:solicitud:12:observada:1790000000',
            'titulo' => 'Solicitud de insumos observada: Tulum',
            'descripcion' => "La solicitud de diciembre 2026 de Tulum tiene observaciones de Zona.\nRevisa y corrige.",
            'url' => 'https://dashboard.ejemplo.test/insumos/estaciones/3',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>|null  $headers
     */
    private function send(array $payload, ?array $headers = null): TestResponse
    {
        $headers ??= $this->signed((string) json_encode($payload));

        return $this->withHeaders($headers)->postJson(self::PATH, $payload);
    }

    // --- Proteccion --------------------------------------------------------------------------------------------------------------

    public function test_unsigned_tampered_or_foreign_requests_are_rejected_and_nothing_is_created(): void
    {
        $payload = $this->payload();

        $this->postJson(self::PATH, $payload)->assertUnauthorized();
        $this->send($this->payload(['titulo' => 'Otro']), $this->signed((string) json_encode($payload)))->assertUnauthorized();
        $this->send($payload, $this->signed((string) json_encode($payload), secret: 'otro-secreto-que-no-es-el-bueno-0123456789'))->assertUnauthorized();

        config(['ecosistema.urls.otro' => 'https://otro.ejemplo.test']);
        $this->send($payload, $this->signed((string) json_encode($payload), system: 'otro'))->assertForbidden();

        config(['ecosistema.puente.secreto_entrada' => null]);
        $this->send($payload)->assertNotFound();

        $this->assertSame(0, Activity::query()->count());
        $this->assertDatabaseMissing('users', ['email' => config('ecosistema.actividades.usuario_sistema.correo')]);
    }

    // --- Creacion ---------------------------------------------------------------------------------------------------------------

    public function test_it_creates_the_activity_for_the_team_assigned_to_its_coordinator_and_notifies_them(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-03-10 15:00', 'UTC'));

        $response = $this->send($this->payload())->assertCreated();

        $activity = Activity::query()->where('external_ref', 'insumos_observada:dashboard:solicitud:12:observada:1790000000')->firstOrFail();
        $this->assertSame(['folio' => $activity->folio, 'url' => '/activities/'.$activity->id, 'creada' => true], $response->json());
        $this->assertMatchesRegularExpression('/^ACT-\d{4}-\d{4}$/', $activity->folio);

        $this->assertSame($this->teamA->id, $activity->team_id);
        $this->assertSame(TicketStatus::Pending, $activity->status);
        $this->assertSame(Priority::High, $activity->priority);
        $this->assertSame(CarbonImmutable::parse(LocalTime::today())->addDays(3)->toDateString(), $activity->due_date->toDateString());
        $this->assertSame('Solicitud de insumos observada: Tulum', $activity->title);
        $this->assertStringContainsString('Revisa y corrige.', $activity->description);
        $this->assertStringContainsString('Origen (Dashboard): https://dashboard.ejemplo.test/insumos/estaciones/3', $activity->description);
        $this->assertNull($activity->recurrence_rule);

        $assignment = $activity->assignments()->sole();
        $this->assertSame($this->coordA->id, $assignment->user_id);
        $this->assertSame(AssignmentRole::Responsable, $assignment->role);
        $this->assertSame(1, $activity->statusHistories()->count());

        $this->assertSame('Dashboard (automático)', $activity->creator->name);
        $notice = $this->coordA->fresh()->notifications->first();
        $this->assertSame(WorkItemAssigned::TYPE, $notice->data['type']);
        $this->assertSame($activity->folio, $notice->data['folio']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ecosystem', 'event' => 'activity_created']);
    }

    public function test_additional_coordinators_collaborate_and_are_notified_while_the_principal_is_responsible(): void
    {
        $second = User::factory()->coordinador()->create(['name' => 'Segundo Coordinador', 'team_id' => $this->teamA->id]);
        $this->teamA->additionalCoordinators()->attach($second->id);

        $this->send($this->payload())->assertCreated();
        $activity = Activity::query()->firstOrFail();

        $roles = $activity->assignments()->pluck('role', 'user_id')->all();
        $this->assertSame(AssignmentRole::Responsable, $roles[$this->coordA->id]);
        $this->assertSame(AssignmentRole::Colaborador, $roles[$second->id]);
        $this->assertCount(2, $roles);

        $this->assertSame($activity->folio, $second->fresh()->notifications->first()->data['folio']);
        $this->assertSame($activity->folio, $this->coordA->fresh()->notifications->first()->data['folio']);
    }

    public function test_an_inactive_additional_coordinator_is_not_assigned(): void
    {
        $second = User::factory()->coordinador()->inactive()->create(['team_id' => $this->teamA->id]);
        $this->teamA->additionalCoordinators()->attach($second->id);

        $this->send($this->payload())->assertCreated();

        $this->assertSame([$this->coordA->id], Activity::query()->firstOrFail()->assignments()->pluck('user_id')->all());
    }

    public function test_the_coordinator_sees_it_and_other_teams_and_employees_do_not(): void
    {
        $this->send($this->payload())->assertCreated();
        $activity = Activity::query()->firstOrFail();

        $this->signIn($this->coordA)->get(route('activities.show', $activity))->assertOk()->assertSee('Solicitud de insumos observada: Tulum');
        $this->signIn($this->coordB)->get(route('activities.show', $activity))->assertNotFound();
        $this->signIn($this->empA1)->get(route('activities.show', $activity))->assertNotFound();
    }

    public function test_the_system_user_cannot_sign_in_and_has_no_role(): void
    {
        $this->send($this->payload())->assertCreated();
        $system = User::query()->where('email', config('ecosistema.actividades.usuario_sistema.correo'))->firstOrFail();

        $this->assertSame(UserStatus::Inactive, $system->status);
        $this->assertNull($system->roleEnum());
        $this->assertFalse($system->canAccessApplication());

        foreach ([UserFactory::DEFAULT_PASSWORD, 'password', ''] as $password) {
            $this->post(route('login'), ['email' => $system->email, 'password' => $password]);
            $this->assertGuest();
        }

        // Una segunda actividad reutiliza al mismo usuario de sistema.
        $this->send($this->payload(['ref' => 'dashboard:solicitud:13:observada:1790000001']))->assertCreated();
        $this->assertSame(1, User::query()->where('email', $system->email)->count());
    }

    // --- Idempotencia -----------------------------------------------------------------------------------------------------------

    public function test_the_same_ref_returns_the_same_activity_without_creating_another(): void
    {
        $first = $this->send($this->payload())->assertCreated()->json();
        $second = $this->send($this->payload())->assertOk()->json();

        $this->assertSame($first['folio'], $second['folio']);
        $this->assertSame($first['url'], $second['url']);
        $this->assertFalse($second['creada']);
        $this->assertSame(1, Activity::query()->count());
        $this->assertCount(1, $this->coordA->fresh()->notifications);
    }

    public function test_a_trashed_activity_with_that_ref_is_not_recreated_and_its_folio_is_not_returned(): void
    {
        $this->send($this->payload())->assertCreated();
        Activity::query()->firstOrFail()->delete();

        $response = $this->send($this->payload())->assertOk()->assertExactJson(['creada' => false, 'eliminada' => true]);

        $this->assertArrayNotHasKey('folio', $response->json());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_the_same_ref_for_another_event_is_another_fact_not_the_other_activity(): void
    {
        $this->send($this->payload(['evento' => 'insumos_observada', 'ref' => 'x:1']))->assertCreated();
        $this->send($this->payload(['evento' => 'factura_cancelada', 'estacion' => null, 'ref' => 'x:1']))->assertCreated();

        $this->assertSame(2, Activity::query()->count());
        $this->assertEqualsCanonicalizing(['insumos_observada:x:1', 'factura_cancelada:x:1'], Activity::query()->pluck('external_ref')->all());
    }

    public function test_new_activities_per_event_and_hour_are_capped_but_known_refs_still_answer(): void
    {
        config(['ecosistema.actividades.limite_por_hora' => 2]);

        $this->send($this->payload(['ref' => 'l:1']))->assertCreated();
        $this->send($this->payload(['ref' => 'l:2']))->assertCreated();
        $this->send($this->payload(['ref' => 'l:3']))->assertStatus(429)->assertExactJson(['error' => 'demasiadas']);
        $this->send($this->payload(['ref' => 'l:1']))->assertOk()->assertJson(['creada' => false]);
        // Otro evento tiene su propio tope.
        $this->send($this->payload(['evento' => 'factura_cancelada', 'estacion' => null, 'ref' => 'l:9']))->assertCreated();

        $this->assertSame(3, Activity::query()->count());

        $this->travel(61)->minutes();
        $this->send($this->payload(['ref' => 'l:3']))->assertCreated();
    }

    public function test_a_pre_registered_account_with_the_system_email_is_never_used(): void
    {
        $email = (string) config('ecosistema.actividades.usuario_sistema.correo');
        $impostor = User::factory()->pending()->create(['email' => $email, 'name' => 'Dashboard (automático)']);

        $this->send($this->payload())->assertStatus(422)->assertExactJson(['error' => 'usuario_sistema_invalido']);
        $this->assertSame(0, Activity::query()->count());

        // Tampoco si alguien la dejo activa o con rol.
        $impostor->forceFill(['status' => UserStatus::Inactive])->save();
        $impostor->assignRole('empleado');
        $this->send($this->payload(['ref' => 'z:2']))->assertStatus(422)->assertExactJson(['error' => 'usuario_sistema_invalido']);
    }

    public function test_the_public_registration_cannot_claim_the_system_email_or_any_invalid_domain(): void
    {
        $coordination = Coordination::factory()->create();

        foreach ([config('ecosistema.actividades.usuario_sistema.correo'), 'cualquiera@algo.invalid', 'Persona@Reservado.INVALID'] as $email) {
            $this->post(route('register'), [
                'name' => 'Persona Prueba', 'email' => $email, 'password' => 'Contrasena-Segura-123', 'password_confirmation' => 'Contrasena-Segura-123',
                'coordination_id' => $coordination->id,
            ])->assertSessionHasErrors('email');
        }

        $this->assertDatabaseMissing('users', ['email' => 'cualquiera@algo.invalid']);
    }

    public function test_the_system_account_is_hidden_and_cannot_be_managed_from_the_interface(): void
    {
        $this->send($this->payload())->assertCreated();
        $system = User::query()->where('email', config('ecosistema.actividades.usuario_sistema.correo'))->firstOrFail();
        $admin = User::factory()->administrador()->create();

        $this->signIn($admin)->get(route('users.index'))->assertOk()->assertDontSee('dashboard-automatico@sistema.invalid')->assertDontSee('Dashboard (automático)');
        $this->get(route('users.show', $system))->assertNotFound();
        $this->post(route('users.approve', $system), ['role' => 'administrador'])->assertNotFound();
        $this->post(route('users.activate', $system))->assertNotFound();

        // Defensa en profundidad: ni siquiera llamando a los servicios.
        $service = app(UserService::class);
        foreach ([
            fn () => $service->activate($admin, $system),
            fn () => $service->approve($admin, $system, UserRole::Administrador, []),
            fn () => $service->updateRoleAndTeams($admin, $system, UserRole::Administrador, []),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Debio rechazarse.');
            } catch (BusinessRuleException) {
                // esperado
            }
        }

        $this->assertSame(UserStatus::Inactive, $system->fresh()->status);
        $this->assertNull($system->fresh()->roleEnum());
    }

    public function test_invisible_and_direction_characters_are_rejected_in_titles_and_descriptions(): void
    {
        foreach (["\u{202E}evil", "a\u{200B}b", "a\u{2028}b"] as $text) {
            $this->send($this->payload(['titulo' => $text]))->assertStatus(422);
            $this->send($this->payload(['descripcion' => $text]))->assertStatus(422);
        }

        $this->send($this->payload(['titulo' => 'Solicitud · Estación', 'descripcion' => "Línea uno\n\tLínea dos con acentos ñ 📌"]))->assertCreated();
    }

    // --- Equipo destino -----------------------------------------------------------------------------------------------------------

    public function test_the_team_can_be_given_by_exact_name(): void
    {
        $this->send($this->payload(['evento' => 'factura_cancelada', 'estacion' => null, 'ref' => 'dashboard:factura:9:cancelada_sat']))->assertCreated();

        $activity = Activity::query()->firstOrFail();
        $this->assertSame($this->teamB->id, $activity->team_id);
        $this->assertSame(Priority::Urgent, $activity->priority);
        $this->assertSame($this->coordB->id, $activity->assignments()->sole()->user_id);
    }

    public function test_a_station_event_uses_the_station_team_and_falls_back_to_the_general_one(): void
    {
        config(['ecosistema.actividades.estaciones' => ['24' => $this->teamB->id]]);

        $this->send($this->payload(['estacion' => '24', 'ref' => 'a:1']))->assertCreated();
        $this->send($this->payload(['estacion' => '99', 'ref' => 'a:2']))->assertCreated();
        $this->send($this->payload(['estacion' => null, 'ref' => 'a:3']))->assertCreated();

        $this->assertSame([$this->teamB->id, $this->teamA->id, $this->teamA->id], Activity::query()->orderBy('id')->pluck('team_id')->all());
    }

    public function test_a_zone_event_ignores_the_station_mapping(): void
    {
        config(['ecosistema.actividades.estaciones' => ['23' => $this->teamA->id]]);

        $this->send($this->payload(['evento' => 'factura_cancelada', 'estacion' => '23', 'ref' => 'f:1']))->assertCreated();

        $this->assertSame($this->teamB->id, Activity::query()->sole()->team_id);
    }

    public function test_without_a_team_for_the_event_nothing_is_created(): void
    {
        $this->send($this->payload(['evento' => 'asistencia_pendiente', 'ref' => 'asis:1']))
            ->assertStatus(422)->assertExactJson(['error' => 'sin_equipo']);

        config(['ecosistema.actividades.eventos.asistencia_pendiente.equipo' => 99999]);
        $this->send($this->payload(['evento' => 'asistencia_pendiente', 'ref' => 'asis:2']))->assertStatus(422)->assertExactJson(['error' => 'sin_equipo']);

        $this->assertSame(0, Activity::query()->count());
        $this->assertDatabaseMissing('users', ['email' => config('ecosistema.actividades.usuario_sistema.correo')]);
    }

    public function test_a_name_shared_by_several_teams_is_not_guessed(): void
    {
        Team::factory()->create(['name' => $this->teamA->name]);
        config(['ecosistema.actividades.eventos.insumos_observada.equipo' => $this->teamA->name]);

        $this->send($this->payload())->assertStatus(422)->assertExactJson(['error' => 'sin_equipo']);
    }

    public function test_a_team_without_an_active_coordinator_cannot_receive_activities(): void
    {
        $this->teamA->update(['coordinator_id' => null]);
        $this->send($this->payload())->assertStatus(422)->assertExactJson(['error' => 'sin_responsable']);

        $this->teamA->update(['coordinator_id' => $this->coordA->id]);
        $this->coordA->forceFill(['status' => UserStatus::Inactive])->save();
        $this->send($this->payload(['ref' => 'r:2']))->assertStatus(422)->assertExactJson(['error' => 'sin_responsable']);

        $this->assertSame(0, Activity::query()->count());
    }

    // --- Validacion ---------------------------------------------------------------------------------------------------------------

    public function test_limits_and_formats_are_enforced(): void
    {
        $this->send($this->payload(['evento' => 'otro_evento']))->assertStatus(422);
        $this->send($this->payload(['ref' => 'con espacios']))->assertStatus(422);
        $this->send($this->payload(['ref' => str_repeat('a', 121)]))->assertStatus(422);
        $this->send($this->payload(['ref' => '']))->assertStatus(422);
        $this->send($this->payload(['estacion' => 'a b']))->assertStatus(422);
        $this->send($this->payload(['titulo' => str_repeat('t', 121)]))->assertStatus(422);
        $this->send($this->payload(['titulo' => "con\nsalto"]))->assertStatus(422);
        $this->send($this->payload(['descripcion' => str_repeat('d', 1001)]))->assertStatus(422);
        $this->send($this->payload(['descripcion' => "bell\x07"]))->assertStatus(422);
        $this->send($this->payload(['url' => 'https://malo.test/robar']))->assertStatus(422);
        $this->send($this->payload(['url' => 'javascript:alert(1)']))->assertStatus(422);
        $this->send($this->payload(['url' => 'https://dashboard.ejemplo.test/ecosistema/salir?codigo=x']))->assertStatus(422);

        $this->assertSame(0, Activity::query()->count());
        $this->assertSame(['error' => 'invalid'], $this->send($this->payload(['evento' => 'otro_evento']))->json());
    }

    public function test_the_url_is_optional_and_text_is_escaped_when_shown(): void
    {
        $this->send($this->payload(['url' => null, 'titulo' => '<b>Aviso</b>', 'descripcion' => '<script>alert(1)</script>']))->assertCreated();
        $activity = Activity::query()->firstOrFail();

        $this->assertStringNotContainsString('Origen', $activity->description);

        $this->signIn($this->coordA)->get(route('activities.show', $activity))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // --- Ayuda de configuracion ---------------------------------------------------------------------------------------------------

    public function test_the_teams_command_lists_ids_and_unconfigured_events(): void
    {
        $this->artisan('ecosystem:teams')
            ->expectsOutputToContain('Equipo A')
            ->expectsOutputToContain('asistencia_pendiente → equipo general: (sin configurar')
            ->assertSuccessful();
    }
}
