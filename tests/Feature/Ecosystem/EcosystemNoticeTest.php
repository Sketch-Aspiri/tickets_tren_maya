<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\EcosystemNotice;
use App\Support\EcosystemUrl;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * Avisos del Dashboard por el puente (fase 5, decision 91): firma del POST (incluye el cuerpo), limites de
 * texto, URL solo del ecosistema, destinatarios solo de cuentas activas y vinculadas, idempotencia por `ref`,
 * campana de Tickets y apertura del aviso con el destino revalidado.
 */
class EcosystemNoticeTest extends DatabaseTestCase
{
    private const FROM_DASHBOARD = 'secreto-dashboard-a-tickets-de-prueba-0123456789';

    private const PATH = '/interno/avisos';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.puente.secreto_entrada' => self::FROM_DASHBOARD,
            'ecosistema.puente.secreto_salida' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.ips' => [],
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $body, string $system = 'dashboard', string $secret = self::FROM_DASHBOARD, ?int $date = null, ?string $nonce = null): array
    {
        $date ??= now()->getTimestamp();
        $nonce ??= Str::random(40);
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
            'correos' => ['persona@ejemplo.test'],
            'titulo' => 'Solicitud de insumos observada',
            'cuerpo' => 'Tulum: revisa las observaciones de Zona.',
            'url' => 'https://dashboard.ejemplo.test/insumos/estaciones/3',
            'ref' => (string) Str::uuid(),
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

    private function linked(string $email = 'persona@ejemplo.test'): User
    {
        return User::factory()->empleado()->create(['email' => $email, 'sso_linked_at' => now()]);
    }

    // --- Proteccion ----------------------------------------------------------------------------------------------------------------

    public function test_without_secret_unsigned_tampered_or_replayed_requests_are_rejected(): void
    {
        $this->linked();
        $payload = $this->payload();

        $this->postJson(self::PATH, $payload)->assertUnauthorized();

        // La firma cubre el cuerpo: cambiar los correos o el texto invalida la peticion.
        $headers = $this->signed((string) json_encode($payload));
        $this->send($this->payload(['correos' => ['otra@ejemplo.test']]), $headers)->assertUnauthorized();
        $this->send($payload, $this->signed((string) json_encode($payload), secret: 'otro-secreto-que-no-es-el-bueno-0123456789'))->assertUnauthorized();
        $this->send($payload, $this->signed((string) json_encode($payload), date: now()->subMinutes(6)->getTimestamp()))->assertUnauthorized();
        $this->send($payload, $this->signed((string) json_encode($payload), system: 'tickets'))->assertUnauthorized();

        $good = $this->signed((string) json_encode($payload));
        $this->send($payload, $good)->assertOk();
        $this->send($payload, $good)->assertUnauthorized();

        config(['ecosistema.puente.secreto_entrada' => null]);
        $this->send($this->payload())->assertNotFound();
    }

    public function test_only_the_dashboard_may_send_notices(): void
    {
        // Un secreto valido para otro sistema del ecosistema no basta: solo el Dashboard avisa.
        config(['ecosistema.urls.otro' => 'https://otro.ejemplo.test']);
        $payload = $this->payload();

        $this->send($payload, $this->signed((string) json_encode($payload), system: 'otro'))->assertForbidden();
    }

    // --- Destinatarios ----------------------------------------------------------------------------------------------------------------

    public function test_only_active_linked_accounts_get_the_notice_and_the_count_does_not_reveal_who_exists(): void
    {
        $linked = $this->linked('vinculada@ejemplo.test');
        $unlinked = User::factory()->empleado()->create(['email' => 'sinvincular@ejemplo.test']);
        $inactive = User::factory()->empleado()->create(['email' => 'baja@ejemplo.test', 'sso_linked_at' => now(), 'status' => UserStatus::Inactive]);
        $pending = User::factory()->pending()->create(['email' => 'pendiente@ejemplo.test', 'sso_linked_at' => now()]);

        $response = $this->send($this->payload(['correos' => [
            'VINCULADA@ejemplo.test', 'vinculada@ejemplo.test', 'sinvincular@ejemplo.test', 'baja@ejemplo.test', 'pendiente@ejemplo.test', 'nadie@ejemplo.test',
        ]]))->assertOk();

        $this->assertSame(['recipients' => 1], $response->json());
        $this->assertCount(1, $linked->notifications);
        foreach ([$unlinked, $inactive, $pending] as $user) {
            $this->assertCount(0, $user->fresh()->notifications);
        }

        $data = $linked->notifications->first()->data;
        $this->assertSame(
            ['type' => EcosystemNotice::TYPE, 'title' => 'Solicitud de insumos observada', 'body' => 'Tulum: revisa las observaciones de Zona.', 'url' => 'https://dashboard.ejemplo.test/insumos/estaciones/3', 'source' => 'dashboard'],
            $data,
        );
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ecosystem', 'event' => 'notice_sent']);
    }

    public function test_the_notice_goes_by_campana_and_web_push(): void
    {
        Notification::fake();
        $user = $this->linked();

        $this->send($this->payload())->assertOk();

        Notification::assertSentTo($user, EcosystemNotice::class, function (EcosystemNotice $notice, array $channels) use ($user): bool {
            $this->assertEqualsCanonicalizing(['database', WebPushChannel::class], $channels);
            $message = $notice->toWebPush($user, $notice)->toArray();

            return $message['title'] === 'Solicitud de insumos observada'
                && $message['data']['url'] === 'https://dashboard.ejemplo.test/insumos/estaciones/3';
        });
    }

    public function test_the_same_ref_does_not_notify_twice(): void
    {
        $user = $this->linked();
        $payload = $this->payload(['ref' => 'aviso-123']);

        $this->send($payload)->assertOk()->assertJson(['recipients' => 1]);
        $this->send($payload)->assertOk()->assertJson(['recipients' => 0]);

        $this->assertCount(1, $user->fresh()->notifications);
    }

    // --- Limites y URL ----------------------------------------------------------------------------------------------------------------

    public function test_text_and_recipient_limits_are_enforced(): void
    {
        $this->linked();

        $this->send($this->payload(['titulo' => str_repeat('a', 61)]))->assertStatus(422);
        $this->send($this->payload(['titulo' => str_repeat('a', 60)]))->assertOk();
        $this->send($this->payload(['cuerpo' => str_repeat('b', 121)]))->assertStatus(422);
        $this->send($this->payload(['cuerpo' => "linea\nsalto"]))->assertStatus(422);
        $this->send($this->payload(['titulo' => '']))->assertStatus(422);
        $this->send($this->payload(['correos' => []]))->assertStatus(422);
        $this->send($this->payload(['correos' => ['no-es-correo']]))->assertStatus(422);
        $this->send($this->payload(['correos' => array_map(fn (int $i): string => "p{$i}@ejemplo.test", range(1, 51))]))->assertStatus(422);
        $this->send($this->payload(['ref' => 'con espacios']))->assertStatus(422);
        $this->assertSame(['error' => 'invalid'], $this->send($this->payload(['url' => 'https://malo.test/robar']))->json());
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function urls(): array
    {
        return [
            'ruta de Tickets' => ['/tickets/12', '/tickets/12'],
            'ruta con consulta' => ['/tickets/pending?scope=team', '/tickets/pending?scope=team'],
            'dashboard' => ['https://dashboard.ejemplo.test/insumos/3', 'https://dashboard.ejemplo.test/insumos/3'],
            'dashboard con consulta' => ['https://dashboard.ejemplo.test/insumos?anio=2026&mes=11', 'https://dashboard.ejemplo.test/insumos?anio=2026&mes=11'],
            'origen en mayusculas' => ['https://DASHBOARD.ejemplo.test/insumos', 'https://dashboard.ejemplo.test/insumos'],
            'otro dominio' => ['https://malo.test/insumos', null],
            'subdominio parecido' => ['https://dashboard.ejemplo.test.malo.test/x', null],
            'usuario en la URL' => ['https://dashboard.ejemplo.test@malo.test/x', null],
            'sin esquema' => ['//malo.test/x', null],
            'esquema http distinto' => ['http://dashboard.ejemplo.test/x', null],
            'javascript' => ['javascript:alert(1)', null],
            'data' => ['data:text/html,<script>alert(1)</script>', null],
            'contrabarra' => ['/\\malo.test', null],
            'doble diagonal' => ['/tickets//12', null],
            'espacio' => ['/tickets/ 12', null],
            'salto de linea' => ["/tickets/1\n2", null],
            'fragmento' => ['/tickets/12#x', null],
            'puerto distinto' => ['https://dashboard.ejemplo.test:8443/x', null],
            'vacia' => ['', null],
            'relativa sin diagonal' => ['tickets/12', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_only_ecosystem_urls_are_accepted_and_they_are_rebuilt(string $url, ?string $expected): void
    {
        $this->assertSame($expected, EcosystemUrl::normalize($url));

        $user = $this->linked();
        $response = $this->send($this->payload(['url' => $url]));

        if ($expected === null) {
            $response->assertStatus(422);
            $this->assertCount(0, $user->fresh()->notifications);
        } else {
            $response->assertOk();
            $this->assertSame($expected, $user->fresh()->notifications->first()->data['url']);
        }
    }

    public function test_the_url_validator_itself_rejects_a_trailing_newline(): void
    {
        // (Por HTTP el middleware TrimStrings lo recorta antes; aqui se prueba el validador solo.)
        $this->assertNull(EcosystemUrl::normalize("/tickets/12\n"));
        $this->assertNull(EcosystemUrl::normalize("https://dashboard.ejemplo.test/x\n"));
    }

    // --- Campana y apertura ----------------------------------------------------------------------------------------------------------

    public function test_the_notice_shows_escaped_in_the_notification_center_and_opens_the_dashboard(): void
    {
        $user = $this->linked();
        $this->send($this->payload(['titulo' => '<b>Aviso</b>', 'cuerpo' => '<script>alert(1)</script>']))->assertOk();
        $notification = $user->fresh()->notifications->first();

        $page = $this->signIn($user)->get(route('notifications.index'))->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;b&gt;Aviso&lt;/b&gt; — &lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->get(route('notifications.open', $notification->id))->assertRedirect('https://dashboard.ejemplo.test/insumos/estaciones/3');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_stored_url_that_is_not_from_the_ecosystem_is_never_followed(): void
    {
        $user = $this->linked();
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => EcosystemNotice::class,
            'data' => ['type' => EcosystemNotice::TYPE, 'title' => 'x', 'body' => 'y', 'url' => 'https://malo.test/robar', 'source' => 'dashboard'],
        ]);
        $notification = $user->notifications()->first();

        $this->signIn($user)->get(route('notifications.open', $notification->id))->assertRedirect(route('notifications.index'));
    }

    public function test_a_relative_url_opens_inside_tickets_and_other_users_cannot_open_the_notice(): void
    {
        $user = $this->linked();
        $this->send($this->payload(['url' => '/tickets/pending']))->assertOk();
        $notification = $user->fresh()->notifications->first();

        $this->signIn($this->linked('otra@ejemplo.test'))->get(route('notifications.open', $notification->id))->assertNotFound();
        $this->signIn($user)->get(route('notifications.open', $notification->id))->assertRedirect(url('/tickets/pending'));
    }

    // --- Service worker ---------------------------------------------------------------------------------------------------------------

    public function test_the_service_worker_opens_only_this_site_or_the_ecosystem(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString('candidate.origin === self.location.origin', $sw);
        $this->assertStringContainsString("candidate.protocol === 'https:'", $sw);
        $this->assertStringContainsString(".endsWith('.zonaorientetm.tech')", $sw);
        $this->assertStringContainsString("const VERSION = 'v2';", $sw);
    }
}
