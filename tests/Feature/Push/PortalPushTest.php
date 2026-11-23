<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Jobs\SendPortalNotice;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Channels\PortalChannel;
use App\Notifications\ChatMessageReceived;
use App\Notifications\EcosystemNotice;
use App\Notifications\TicketArrivedInPool;
use App\Notifications\WorkItemAssigned;
use App\Notifications\WorkItemCommented;
use App\Notifications\WorkItemRejected;
use App\Notifications\WorkItemStatusChanged;
use App\Services\AssignmentService;
use App\Services\Ecosystem\BridgeSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use NotificationChannels\WebPush\WebPushChannel;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Push general por el PWA del portal: con ECOSISTEMA_PUSH_DESTINO=portal los eventos de Tickets salen hacia el portal
 * por el puente firmado (el mismo mensaje que antes iba al push local); con `local` todo sigue como antes. La campana
 * (`database`) no cambia en ningún caso.
 */
class PortalPushTest extends DatabaseTestCase
{
    private const SECRET = 'secreto-tickets-a-portal-de-prueba-0123456789abc';

    private const PATH = '/interno/subsistemas/notificaciones';

    private Team $team;

    private User $jefe;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.push.destino' => 'portal',
            'ecosistema.puente.portal.secreto' => self::SECRET,
            'ecosistema.puente.portal.url' => 'https://portal.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
        ]);

        $this->team = Team::factory()->create();
        $this->jefe = User::factory()->jefe()->create();
        $this->ana = User::factory()->empleado()->create(['team_id' => $this->team->id, 'email' => 'Ana@Ejemplo.test']);
    }

    /** @return list<object> */
    private function notifications(): array
    {
        return [
            new WorkItemAssigned('ticket', 7, 'TM-2026-0007', 'Falla', 'responsable', 'Jefe'),
            new WorkItemCommented('ticket', 7, 'TM-2026-0007', 'Falla', 'Jefe'),
            new WorkItemRejected('ticket', 7, 'TM-2026-0007', 'Falla', 'Jefe'),
            new WorkItemStatusChanged('ticket', 7, 'TM-2026-0007', 'En proceso', 'Jefe'),
            new TicketArrivedInPool(7, 'TM-2026-0007', 'Falla', 'Soporte', 'Jefe'),
            new ChatMessageReceived(3, 'Jefe', null),
            new EcosystemNotice('Título', 'Cuerpo', 'https://dashboard.ejemplo.test/inicio', 'dashboard'),
        ];
    }

    public function test_con_destino_portal_los_siete_eventos_usan_el_canal_del_portal_y_conservan_la_campana(): void
    {
        foreach ($this->notifications() as $notification) {
            $via = $notification->via($this->ana);

            $this->assertContains(PortalChannel::class, $via, $notification::class);
            $this->assertNotContains(WebPushChannel::class, $via, $notification::class);
        }

        // La campana de Tickets no cambia.
        $this->assertContains('database', (new WorkItemAssigned('ticket', 7, 'TM-2026-0007', 'Falla', 'responsable', 'Jefe'))->via($this->ana));
    }

    public function test_con_destino_local_todo_sigue_igual(): void
    {
        config(['ecosistema.push.destino' => 'local']);

        foreach ($this->notifications() as $notification) {
            $via = $notification->via($this->ana);

            $this->assertContains(WebPushChannel::class, $via, $notification::class);
            $this->assertNotContains(PortalChannel::class, $via, $notification::class);
        }
    }

    public function test_el_canal_manda_el_mismo_mensaje_al_portal_con_url_absoluta_y_correo_en_minusculas(): void
    {
        Queue::fake();
        $notification = new WorkItemAssigned('ticket', 7, 'TM-2026-0007', 'Falla', 'responsable', 'Jefe');

        (new PortalChannel)->send($this->ana, $notification);

        Queue::assertPushed(SendPortalNotice::class, function (SendPortalNotice $job) use ($notification) {
            $message = $notification->toWebPush($this->ana, $notification)->toArray();

            return $job->emails === ['ana@ejemplo.test']
                && $job->title === $message['title']
                && $job->body === $message['body']
                && $job->url === 'https://tickets.ejemplo.test'.route('tickets.show', 7, false)
                && $job->module === 'tickets'
                && preg_match('/^[0-9a-f-]{36}$/', $job->ref) === 1
                && strlen($job->title) <= 60 && strlen($job->body) <= 120;
        });
    }

    public function test_los_textos_se_limpian_y_se_recortan_a_los_limites_del_portal(): void
    {
        Queue::fake();
        $largo = new EcosystemNotice(str_repeat('T', 90)."\u{202E}x", "Línea1\nLínea2 ".str_repeat('c', 200), 'https://dashboard.ejemplo.test/inicio', 'dashboard');

        (new PortalChannel)->send($this->ana, $largo);

        Queue::assertPushed(SendPortalNotice::class, function (SendPortalNotice $job) {
            return mb_strlen($job->title) === 60 && mb_strlen($job->body) === 120
                && preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $job->title.$job->body) === 0;
        });
    }

    public function test_cuentas_tecnicas_y_destinatarios_que_no_son_usuarios_se_omiten(): void
    {
        Queue::fake();
        $tecnico = User::factory()->empleado()->create(['email' => 'dashboard-automatico@sistema.invalid']);
        $notification = new WorkItemAssigned('ticket', 7, 'TM-2026-0007', 'Falla', 'responsable', 'Jefe');

        (new PortalChannel)->send($tecnico, $notification);
        (new PortalChannel)->send(new \stdClass, $notification);

        Queue::assertNothingPushed();
    }

    public function test_un_fallo_al_encolar_no_rompe_la_accion_que_origino_el_aviso(): void
    {
        Queue::shouldReceive('push')->andThrow(new RuntimeException('cola caída'));
        $notification = new WorkItemAssigned('ticket', 7, 'TM-2026-0007', 'Falla', 'responsable', 'Jefe');

        (new PortalChannel)->send($this->ana, $notification);

        $this->assertTrue(true); // llegó aquí sin excepción
    }

    public function test_asignar_un_ticket_llega_al_portal_de_punta_a_punta_solo_para_quien_se_suma(): void
    {
        // Cola síncrona (phpunit.xml): asignación -> notificación -> canal del portal -> job -> POST firmado.
        Http::fake(['https://portal.ejemplo.test/*' => Http::response(['recipients' => 1])]);
        $ticket = Ticket::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->jefe->id]);

        app(AssignmentService::class)->assign($this->jefe, $ticket, $this->ana->id, []);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://portal.ejemplo.test'.self::PATH
            && $request['correos'] === ['ana@ejemplo.test']
            && str_starts_with($request['url'], 'https://tickets.ejemplo.test/tickets/')
            && $request['modulo'] === 'tickets');
        $this->assertSame(1, $this->ana->notifications()->count()); // la campana de Tickets sigue
    }

    public function test_el_job_firma_el_post_con_el_secreto_del_portal_y_el_sistema_tickets(): void
    {
        Http::fake(['https://portal.ejemplo.test/*' => Http::response(['recipients' => 1])]);

        (new SendPortalNotice(['ana@ejemplo.test'], 'Título', 'Cuerpo', 'https://tickets.ejemplo.test/tickets/7', 'ref-0001', 'tickets'))
            ->handle(app(BridgeSignature::class));

        Http::assertSent(function (Request $request) {
            $esperada = hash_hmac('sha256', implode("\n", [
                'POST', self::PATH, '', 'tickets', $request->header('X-Puente-Fecha')[0], $request->header('X-Puente-Nonce')[0], hash('sha256', $request->body()),
            ]), self::SECRET);

            return $request->url() === 'https://portal.ejemplo.test'.self::PATH
                && $request->header('X-Puente-Sistema')[0] === 'tickets'
                && hash_equals($esperada, $request->header('X-Puente-Firma')[0])
                && $request['correos'] === ['ana@ejemplo.test'] && $request['ref'] === 'ref-0001' && $request['modulo'] === 'tickets';
        });
    }

    public function test_sin_el_puente_del_portal_configurado_el_job_no_hace_nada(): void
    {
        Http::fake();
        config(['ecosistema.puente.portal.secreto' => null]);

        (new SendPortalNotice(['ana@ejemplo.test'], 'T', 'C', 'https://tickets.ejemplo.test/tickets/7', 'ref-0002'))
            ->handle(app(BridgeSignature::class));

        Http::assertNothingSent();
    }

    public function test_si_el_portal_rechaza_o_no_responde_el_job_falla_para_reintentarse_sin_revelar_detalles(): void
    {
        Http::fake(['https://portal.ejemplo.test/*' => Http::response(['error' => 'unauthorized'], 401)]);

        try {
            (new SendPortalNotice(['ana@ejemplo.test'], 'T', 'C', 'https://tickets.ejemplo.test/tickets/7', 'ref-0003'))->handle(app(BridgeSignature::class));
            $this->fail('Debió lanzar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        Http::fake(fn () => throw new ConnectionException('cURL error con '.self::SECRET));

        try {
            (new SendPortalNotice(['ana@ejemplo.test'], 'T', 'C', 'https://tickets.ejemplo.test/tickets/7', 'ref-0004'))->handle(app(BridgeSignature::class));
            $this->fail('Debió lanzar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function test_el_job_reintenta_con_espera_creciente(): void
    {
        $job = new SendPortalNotice(['a@ejemplo.test'], 'T', 'C', 'https://tickets.ejemplo.test/tickets/7', 'ref-0005');

        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 600], $job->backoff());
    }

    public function test_con_destino_portal_la_pantalla_de_notificaciones_invita_a_usar_el_portal(): void
    {
        $html = Blade::render('<x-push-toggle />');

        $this->assertStringContainsString(__('push.ui.moved_to_portal'), $html);
        $this->assertStringContainsString('/notificaciones', $html);
        $this->assertStringNotContainsString('x-data="pushToggle"', $html);

        config(['ecosistema.push.destino' => 'local']);
        $this->assertStringContainsString('x-data="pushToggle"', Blade::render('<x-push-toggle />'));
    }

    public function test_el_service_worker_puede_abrir_el_portal(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("'zonaorientetm.tech', 'tickets.zonaorientetm.tech', 'dashboard.zonaorientetm.tech'", $sw);
        $this->assertStringContainsString("const VERSION = 'v4';", $sw);
    }
}
