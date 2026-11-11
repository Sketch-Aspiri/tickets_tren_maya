<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Team;
use App\Models\User;
use App\Notifications\WorkItemAssigned;
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

        $activity = Activity::query()->where('external_ref', 'dashboard:solicitud:12:observada:1790000000')->firstOrFail();
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

    public function test_a_trashed_activity_with_that_ref_is_not_recreated(): void
    {
        $this->send($this->payload())->assertCreated();
        Activity::query()->firstOrFail()->delete();

        $this->send($this->payload())->assertOk()->assertJson(['creada' => false]);
        $this->assertSame(0, Activity::query()->count());
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
