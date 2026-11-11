<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Coordination;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Puente interno con el Dashboard (decision 89): firma HMAC por direccion, ventana de tiempo, nonce de un solo
 * uso, IPs permitidas, resumen de "Mis pendientes" con el mismo alcance que la vista y solo para cuentas
 * vinculadas, y el ticket a Soporte prellenado con el contexto que deja el Dashboard.
 */
class EcosystemBridgeTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private const FROM_DASHBOARD = 'secreto-dashboard-a-tickets-de-prueba-0123456789';

    private const TO_DASHBOARD = 'secreto-tickets-a-dashboard-de-prueba-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        config([
            'ecosistema.puente.secreto_entrada' => self::FROM_DASHBOARD,
            'ecosistema.puente.secreto_salida' => self::TO_DASHBOARD,
            'ecosistema.puente.url' => 'https://dashboard.ejemplo.test',
            'ecosistema.puente.ips' => [],
        ]);
    }

    /**
     * Encabezados como los firmaria el Dashboard (mismo formato que FirmaPuente).
     *
     * @param  array<string, string>  $query
     * @return array<string, string>
     */
    private function signed(string $path, array $query, ?int $date = null, ?string $nonce = null, string $secret = self::FROM_DASHBOARD, string $system = 'dashboard'): array
    {
        $date ??= now()->getTimestamp();
        $nonce ??= Str::random(40);
        ksort($query);
        $canonical = implode("\n", ['GET', $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $system, (string) $date, $nonce, hash('sha256', '')]);

        return [
            'X-Puente-Sistema' => $system,
            'X-Puente-Fecha' => (string) $date,
            'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonical, $secret),
        ];
    }

    private function askPending(User $user, array $headers = []): TestResponse
    {
        $query = ['correo' => $user->email];

        return $this->withHeaders($headers ?: $this->signed('/interno/pendientes', $query))
            ->get('/interno/pendientes?'.http_build_query($query), ['Accept' => 'application/json']);
    }

    private function link(User $user): User
    {
        $user->forceFill(['sso_linked_at' => now()])->save();

        return $user;
    }

    // --- Proteccion del puente ----------------------------------------------------------------------------------------------------

    public function test_without_secret_the_bridge_does_not_exist(): void
    {
        config(['ecosistema.puente.secreto_entrada' => null]);

        $this->askPending($this->link($this->empA1))->assertNotFound();
    }

    public function test_a_valid_signature_is_accepted_once(): void
    {
        $headers = $this->signed('/interno/pendientes', ['correo' => $this->link($this->empA1)->email]);

        $this->askPending($this->empA1, $headers)->assertOk()->assertJsonPath('linked', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->askPending($this->empA1, $headers)->assertUnauthorized();
    }

    public function test_wrong_secret_wrong_system_or_tampered_query_is_rejected(): void
    {
        $this->link($this->empA1);
        $email = $this->empA1->email;

        $this->askPending($this->empA1, $this->signed('/interno/pendientes', ['correo' => $email], secret: self::TO_DASHBOARD))->assertUnauthorized();
        $this->askPending($this->empA1, $this->signed('/interno/pendientes', ['correo' => $email], system: 'tickets'))->assertUnauthorized();
        // Firmado para otra persona: la consulta no coincide con la firma.
        $this->askPending($this->empA1, $this->signed('/interno/pendientes', ['correo' => $this->empA2->email]))->assertUnauthorized();
        $this->get('/interno/pendientes?correo='.urlencode($email), ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_dates_outside_the_window_are_rejected(): void
    {
        $email = $this->link($this->empA1)->email;

        $this->askPending($this->empA1, $this->signed('/interno/pendientes', ['correo' => $email], date: now()->subMinutes(6)->getTimestamp()))->assertUnauthorized();
        $this->askPending($this->empA1, $this->signed('/interno/pendientes', ['correo' => $email], date: now()->addMinutes(6)->getTimestamp()))->assertUnauthorized();
        $this->askPending($this->empA1, $this->signed('/interno/pendientes', ['correo' => $email], date: now()->subMinutes(4)->getTimestamp()))->assertOk();
    }

    public function test_only_configured_ips_may_call(): void
    {
        $this->link($this->empA1);
        config(['ecosistema.puente.ips' => ['10.0.0.5']]);

        $this->askPending($this->empA1)->assertForbidden();

        config(['ecosistema.puente.ips' => ['127.0.0.1']]);

        $this->askPending($this->empA1)->assertOk();
    }

    public function test_the_bridge_has_no_session_and_does_not_require_csrf(): void
    {
        $this->askPending($this->link($this->empA1))->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    // --- Resumen de pendientes ----------------------------------------------------------------------------------------------------

    public function test_unknown_inactive_or_unlinked_accounts_get_no_data(): void
    {
        $ticket = $this->makeTicket($this->teamA);
        $ticket->assignments()->create(['user_id' => $this->empA1->id, 'role' => AssignmentRole::Responsable, 'assigned_by' => $this->jefe->id]);

        // Sin vincular: el correo solo no prueba identidad (decision 88).
        $this->askPending($this->empA1)->assertOk()->assertExactJson(['linked' => false]);

        $ghost = User::factory()->make(['email' => 'nadie@ejemplo.test']);
        $this->askPending($ghost)->assertOk()->assertExactJson(['linked' => false]);

        $this->link($this->empA2)->forceFill(['status' => UserStatus::Inactive])->save();
        $this->askPending($this->empA2)->assertOk()->assertExactJson(['linked' => false]);
    }

    public function test_summary_matches_my_pending_scope_and_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-03-10 15:00', 'UTC'));
        $today = LocalTime::today();
        $this->link($this->empA1);

        $assign = function (Ticket $ticket, User $user): void {
            $ticket->assignments()->create(['user_id' => $user->id, 'role' => AssignmentRole::Responsable, 'assigned_by' => $this->jefe->id]);
        };

        $late = $this->makeTicket($this->teamA, attributes: ['title' => 'Vencido <b>', 'due_date' => CarbonImmutable::parse($today)->subDays(2)]);
        $assign($late, $this->empA1);
        $review = $this->makeTicket($this->teamA, attributes: ['title' => 'En revision', 'status' => TicketStatus::InReview]);
        $assign($review, $this->empA1);
        $done = $this->makeTicket($this->teamA, attributes: ['status' => TicketStatus::Completed]);
        $assign($done, $this->empA1);
        $others = $this->makeTicket($this->teamA, attributes: ['title' => 'De otra persona']);
        $assign($others, $this->empA2);
        $this->makeTicket($this->teamA, attributes: ['title' => 'En la bolsa']);

        $activity = $this->makeActivity($this->teamA, ['title' => 'Reporte semanal', 'due_date' => CarbonImmutable::parse($today)->addDay()], $this->empA1);

        $response = $this->askPending($this->empA1)->assertOk()
            ->assertJsonPath('counts', ['tickets' => 2, 'activities' => 1, 'overdue' => 1, 'in_review' => 1])
            ->assertJsonPath('pending_url', '/tickets/pending');

        $urgent = $response->json('urgent');
        $this->assertSame([$late->folio, $activity->folio, $review->folio], array_column($urgent, 'folio'));
        $this->assertSame('Vencido <b>', $urgent[0]['title'], 'texto tal cual: el Dashboard lo escapa al mostrarlo');
        $this->assertTrue($urgent[0]['overdue']);
        $this->assertSame('/tickets/'.$late->id, $urgent[0]['url']);
        $this->assertSame('/activities/'.$activity->id, $urgent[1]['url']);
        $this->assertArrayNotHasKey('priority_weight', $urgent[0]);
        $this->assertStringNotContainsString('De otra persona', $response->getContent());
        $this->assertStringNotContainsString('En la bolsa', $response->getContent());
    }

    // --- Ticket a Soporte prellenado ----------------------------------------------------------------------------------------------

    private function fakeContext(int $status = 200, array $body = []): void
    {
        Http::fake(['https://dashboard.ejemplo.test/interno/contextos/*' => Http::response($body ?: [
            'titulo' => "Falla en el Dashboard: /insumos\x07",
            'descripcion' => "Pagina: /insumos\nRol: Estacion\n\nDescribe que paso:",
        ], $status)]);
    }

    public function test_prefill_fills_title_description_and_support_coordination(): void
    {
        $support = Coordination::factory()->create(['name' => 'Soporte del sistema']);
        $this->fakeContext();
        $id = Str::random(40);

        $this->signIn($this->empA1)->get('/tickets/create?prefill='.$id)->assertOk()
            ->assertSee('value="Falla en el Dashboard: /insumos"', false)
            ->assertSee("Pagina: /insumos\nRol: Estacion", false)
            ->assertViewHas('defaultCoordinationId', $support->id)
            ->assertViewHas('defaultTeamId', null);

        Http::assertSent(function ($request) use ($id): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/interno/contextos/'.$id)
                && $query === ['correo' => mb_strtolower($this->empA1->email)]
                && $request->hasHeader('X-Puente-Firma')
                && $request->header('X-Puente-Sistema') === ['tickets'];
        });
    }

    public function test_prefill_is_ignored_when_invalid_missing_or_dashboard_down(): void
    {
        $this->fakeContext(404);

        $this->signIn($this->empA1)->get('/tickets/create?prefill='.Str::random(40))->assertOk()
            ->assertViewHas('defaultTeamId', $this->teamA->id)
            ->assertDontSee('Falla en el Dashboard');

        // Formato invalido: ni siquiera se pregunta.
        Http::fake();
        $this->get('/tickets/create?prefill=../../etc')->assertOk();
        Http::assertNothingSent();

        // Sin secreto de salida, tampoco.
        config(['ecosistema.puente.secreto_salida' => null]);
        $this->get('/tickets/create?prefill='.Str::random(40))->assertOk();
        Http::assertNothingSent();
    }

    public function test_html_in_the_context_is_escaped(): void
    {
        $this->fakeContext(body: ['titulo' => '<script>alert(1)</script>', 'descripcion' => '<img src=x onerror=alert(1)>']);

        $this->signIn($this->empA1)->get('/tickets/create?prefill='.Str::random(40))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;', false);
    }
}
