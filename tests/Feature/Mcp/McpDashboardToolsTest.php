<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\McpClient;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\DatabaseTestCase;

/**
 * Herramientas del Dashboard en el MCP de Tickets (decision 97): cada cliente ve solo las que su nivel permite
 * (none / read / write), la llamada viaja firmada por el puente con el cuerpo cubierto por la firma, la lista se guarda
 * unos minutos y cualquier falla del Dashboard llega a la IA como un mensaje, no como un error del servidor.
 */
class McpDashboardToolsTest extends DatabaseTestCase
{
    private const SECRETO = 'secreto-tickets-a-dashboard-de-prueba-0123456789';

    private const BASE = 'https://dashboard.ejemplo.test';

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.puente.secreto_salida' => self::SECRETO,
            'ecosistema.puente.url' => self::BASE,
            'ecosistema.sistema' => 'tickets',
        ]);
        Cache::flush();
        $this->account = User::factory()->coordinador()->create();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            ['name' => 'consultar_personal', 'description' => 'Consulta personal.', 'inputSchema' => ['type' => 'object', 'properties' => ['operacion' => ['type' => 'string']], 'additionalProperties' => false], 'escritura' => false],
            ['name' => 'catalogo_dashboard', 'description' => 'Catálogo.', 'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false], 'escritura' => false],
            ['name' => 'capturar_asistencia', 'description' => 'Captura.', 'inputSchema' => ['type' => 'object', 'properties' => ['estacion' => ['type' => 'string']], 'required' => ['estacion']], 'escritura' => true],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $postResponse
     */
    private function fakeDashboard(?array $postResponse = null, int $postStatus = 200): void
    {
        Http::fake([
            self::BASE.'/interno/ia/herramientas' => Http::response(['herramientas' => $this->definitions()]),
            self::BASE.'/interno/ia/herramientas/*' => Http::response($postResponse ?? ['contenido' => '{"ok":true}', 'error' => false, 'fuentes' => []], $postStatus),
        ]);
    }

    /**
     * @return array{0: McpClient, 1: string}
     */
    private function client(string $name, string $dashboard, bool $write = false): array
    {
        [$plain, $hash] = McpClient::newToken();
        $client = new McpClient(['name' => $name, 'user_id' => $this->account->getKey(), 'can_write' => $write, 'dashboard_access' => $dashboard]);
        $client->forceFill(['token_hash' => $hash])->save();

        return [$client, $plain];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return TestResponse
     */
    private function rpc(string $token, string $method, array $params = [])
    {
        return $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$token,
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params])->assertOk();
    }

    /**
     * @return list<string>
     */
    private function toolNames(string $token): array
    {
        return array_column($this->rpc($token, 'tools/list')->json('result.tools'), 'name');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: string}
     */
    private function callTool(string $token, string $tool, array $arguments = []): array
    {
        $result = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass : $arguments])->json('result');

        return [(bool) ($result['isError'] ?? false), (string) ($result['content'][0]['text'] ?? '')];
    }

    private function dashboardPosts(): int
    {
        return Http::recorded(fn (HttpRequest $request) => $request->method() === 'POST')->count();
    }

    // ---- Quién ve qué ----

    public function test_a_client_without_dashboard_access_sees_no_dashboard_tools_and_causes_no_call(): void
    {
        $this->fakeDashboard();
        [, $token] = $this->client('sin-acceso', McpClient::DASHBOARD_NONE, true);

        $names = $this->toolNames($token);

        $this->assertNotContains('dashboard_consultar_personal', $names);
        $this->assertContains('quien_soy', $names);
        Http::assertNothingSent();
    }

    public function test_clients_created_before_this_feature_default_to_no_access(): void
    {
        $client = new McpClient(['name' => 'viejo', 'user_id' => $this->account->getKey(), 'can_write' => true]);
        $client->forceFill(['token_hash' => hash('sha256', 'x')])->save();

        $this->assertSame('none', $client->refresh()->dashboard_access);
    }

    public function test_read_access_shows_only_the_read_tools(): void
    {
        $this->fakeDashboard();
        [, $token] = $this->client('lector', McpClient::DASHBOARD_READ);

        $names = $this->toolNames($token);

        $this->assertContains('dashboard_consultar_personal', $names);
        $this->assertContains('dashboard_catalogo_dashboard', $names);
        $this->assertNotContains('dashboard_capturar_asistencia', $names);
    }

    public function test_write_access_needs_a_client_that_can_write_to_show_the_write_tool(): void
    {
        $this->fakeDashboard();
        [, $writer] = $this->client('escritor', McpClient::DASHBOARD_WRITE, true);
        [, $readOnly] = $this->client('lector-con-nivel-write', McpClient::DASHBOARD_WRITE, false);

        $this->assertContains('dashboard_capturar_asistencia', $this->toolNames($writer));
        $this->assertNotContains('dashboard_capturar_asistencia', $this->toolNames($readOnly));
    }

    public function test_the_published_schema_is_the_dashboards_and_empty_properties_stay_an_object(): void
    {
        $this->fakeDashboard();
        [, $token] = $this->client('lector', McpClient::DASHBOARD_READ);

        $response = $this->rpc($token, 'tools/list');
        $tools = collect($response->json('result.tools'))->keyBy('name');

        $this->assertSame(['type' => 'string'], $tools['dashboard_consultar_personal']['inputSchema']['properties']['operacion']);
        $this->assertTrue($tools['dashboard_consultar_personal']['annotations']['readOnlyHint']);
        $this->assertStringContainsString('[Dashboard] Catálogo.', $tools['dashboard_catalogo_dashboard']['description']);
        $this->assertMatchesRegularExpression('/"name":"dashboard_catalogo_dashboard".*?"properties":\{\}/s', $response->getContent());
    }

    public function test_the_definitions_are_cached_and_malformed_ones_are_dropped(): void
    {
        Http::fake([self::BASE.'/interno/ia/herramientas' => Http::response(['herramientas' => [
            ...$this->definitions(),
            ['name' => 'Mala-Herramienta', 'description' => 'x', 'inputSchema' => ['type' => 'object']],
            ['name' => 'sin_esquema', 'description' => 'x'],
            'texto suelto',
        ]])]);
        [, $token] = $this->client('lector', McpClient::DASHBOARD_READ);

        $first = $this->toolNames($token);
        $this->toolNames($token);

        $this->assertNotContains('dashboard_Mala-Herramienta', $first);
        $this->assertNotContains('dashboard_sin_esquema', $first);
        $this->assertCount(1, Http::recorded());
    }

    public function test_when_the_dashboard_is_down_the_other_tools_still_work_and_nothing_is_cached(): void
    {
        // Primero el Dashboard falla (500) y luego responde: una respuesta con error no debe quedar guardada.
        Http::fake([self::BASE.'/interno/ia/herramientas' => Http::sequence()
            ->pushStatus(500)
            ->push(['herramientas' => $this->definitions()]),
        ]);
        [, $token] = $this->client('lector', McpClient::DASHBOARD_READ);

        $names = $this->toolNames($token);

        $this->assertContains('quien_soy', $names);
        $this->assertNotContains('dashboard_consultar_personal', $names);

        // El fallo se recuerda un rato: no se vuelve a esperar al Dashboard en cada peticion MCP.
        $this->assertNotContains('dashboard_consultar_personal', $this->toolNames($token));
        $this->assertCount(1, Http::recorded());

        // Pasado ese rato se pregunta de nuevo y, como ya responde, aparecen.
        $this->travel(60)->seconds();
        $this->assertContains('dashboard_consultar_personal', $this->toolNames($token));
    }

    public function test_the_dashboard_cannot_downgrade_a_write_tool_to_read_only(): void
    {
        $lista = $this->definitions();
        $lista[2]['escritura'] = false;                                  // el Dashboard "dice" que capturar_asistencia solo lee
        $lista[] = ['name' => 'borrar_todo', 'description' => 'Nueva.', 'inputSchema' => ['type' => 'object', 'properties' => []], 'escritura' => false];
        Http::fake([self::BASE.'/interno/ia/herramientas' => Http::response(['herramientas' => $lista])]);
        [, $reader] = $this->client('lector', McpClient::DASHBOARD_READ);
        [, $writer] = $this->client('escritor', McpClient::DASHBOARD_WRITE, true);

        $readerTools = $this->toolNames($reader);
        $writerTools = collect($this->rpc($writer, 'tools/list')->json('result.tools'))->keyBy('name');

        // Lo que Tickets no conoce como solo lectura se trata como escritura.
        $this->assertNotContains('dashboard_capturar_asistencia', $readerTools);
        $this->assertNotContains('dashboard_borrar_todo', $readerTools);
        $this->assertContains('dashboard_consultar_personal', $readerTools);
        $this->assertTrue($writerTools->has('dashboard_capturar_asistencia'));
        $this->assertTrue($writerTools['dashboard_capturar_asistencia']['annotations']['destructiveHint']);
        $this->assertFalse($writerTools['dashboard_consultar_personal']['annotations']['destructiveHint']);
    }

    public function test_duplicated_oversized_or_too_many_definitions_never_break_the_server(): void
    {
        $lista = [
            ...$this->definitions(),
            $this->definitions()[0],                                     // nombre repetido
            ['name' => 'esquema_enorme', 'description' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'description' => str_repeat('x', 30000)]]]],
        ];

        foreach (range(1, 40) as $i) {
            $lista[] = ['name' => 'extra_'.str_repeat('a', $i % 20 + 1), 'description' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => []]];
        }

        Http::fake([self::BASE.'/interno/ia/herramientas' => Http::response(['herramientas' => $lista])]);
        [, $token] = $this->client('escritor', McpClient::DASHBOARD_WRITE, true);

        $names = array_filter($this->toolNames($token), fn (string $name): bool => str_starts_with($name, 'dashboard_'));

        $this->assertSame(count($names), count(array_unique($names)));
        $this->assertNotContains('dashboard_esquema_enorme', $names);
        $this->assertLessThanOrEqual(30, count($names));
    }

    // ---- Llamadas ----

    public function test_a_call_is_signed_over_the_exact_body_and_carries_the_client_name(): void
    {
        $this->fakeDashboard(['contenido' => '{"total":3}', 'error' => false, 'fuentes' => [
            ['titulo' => 'Personal', 'url' => 'https://dashboard.ejemplo.test/agenda/personal'],
            ['titulo' => 'Otro host', 'url' => 'https://malo.example.org/x'],
            ['titulo' => 'Sin TLS', 'url' => 'http://dashboard.ejemplo.test/x'],
            ['titulo' => 'Script', 'url' => 'javascript:alert(1)'],
        ]]);
        [, $token] = $this->client('grokbot-lectura', McpClient::DASHBOARD_READ);

        [$isError, $text] = $this->callTool($token, 'dashboard_consultar_personal', ['operacion' => 'contar']);

        $this->assertFalse($isError);
        $this->assertStringContainsString(__('mcp.dashboard.untrusted_notice'), $text);
        $this->assertStringContainsString('{"total":3}', $text);
        $this->assertStringContainsString('https://dashboard.ejemplo.test/agenda/personal', $text);
        $this->assertStringNotContainsString('malo.example.org', $text);
        $this->assertStringNotContainsString('http://dashboard', $text);
        $this->assertStringNotContainsString('javascript:', $text);

        Http::assertSent(function (HttpRequest $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->body();
            $this->assertSame(self::BASE.'/interno/ia/herramientas/consultar_personal', $request->url());
            $this->assertSame(['cliente' => 'grokbot-lectura', 'entrada' => ['operacion' => 'contar']], json_decode($body, true));

            $canonical = implode("\n", ['POST', '/interno/ia/herramientas/consultar_personal', '', 'tickets', $request->header('X-Puente-Fecha')[0], $request->header('X-Puente-Nonce')[0], hash('sha256', $body)]);
            $this->assertSame(hash_hmac('sha256', $canonical, self::SECRETO), $request->header('X-Puente-Firma')[0]);

            return true;
        });
    }

    public function test_a_read_only_level_cannot_call_the_write_tool_even_by_name(): void
    {
        $this->fakeDashboard();
        [, $token] = $this->client('lector', McpClient::DASHBOARD_READ);

        $response = $this->withHeaders(['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer '.$token, 'MCP-Protocol-Version' => '2025-06-18'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'dashboard_capturar_asistencia', 'arguments' => ['estacion' => 'Tulum']]]);

        $this->assertTrue($response->status() >= 400 || $response->json('result.isError') === true || $response->json('error') !== null);
        $this->assertSame(0, $this->dashboardPosts());
    }

    public function test_writes_are_audited_and_reads_are_not(): void
    {
        $this->fakeDashboard();
        [, $token] = $this->client('escritor', McpClient::DASHBOARD_WRITE, true);

        $this->callTool($token, 'dashboard_consultar_personal', ['operacion' => 'contar']);
        $this->assertFalse(ActivityLogEntry::query()->where('log_name', 'mcp')->where('event', 'dashboard_write')->exists());

        $this->callTool($token, 'dashboard_capturar_asistencia', ['estacion' => 'Tulum']);
        $entry = ActivityLogEntry::query()->where('log_name', 'mcp')->where('event', 'dashboard_write')->sole();
        $this->assertSame('capturar_asistencia', $entry->properties['tool']);
        $this->assertSame('escritor', $entry->properties['client']);
        $this->assertStringNotContainsString('Tulum', (string) json_encode($entry->properties));
    }

    public function test_an_error_flagged_by_the_dashboard_reaches_the_ai_as_a_tool_error(): void
    {
        $this->fakeDashboard(['contenido' => 'No encontré una estación operativa con ese nombre o código.', 'error' => true, 'fuentes' => []]);
        [, $token] = $this->client('escritor', McpClient::DASHBOARD_WRITE, true);

        [$isError, $text] = $this->callTool($token, 'dashboard_capturar_asistencia', ['estacion' => 'Nada']);

        $this->assertTrue($isError);
        $this->assertSame('No encontré una estación operativa con ese nombre o código.', $text);
    }

    public function test_dashboard_failures_become_clear_messages_without_internal_details(): void
    {
        [, $token] = $this->client('lector', McpClient::DASHBOARD_READ);
        Cache::put('ecosistema:ia:herramientas-dashboard', array_map(fn (array $d): array => $d, $this->definitions()), now()->addMinutes(5));

        Http::fake([self::BASE.'/interno/ia/herramientas/*' => Http::sequence()
            ->pushStatus(404)
            ->pushStatus(422)
            ->pushStatus(500)
            ->pushFailedConnection('cURL error 28: connection timed out'),
        ]);

        foreach (['not_enabled', 'invalid', 'unavailable', 'unavailable'] as $key) {
            [$isError, $text] = $this->callTool($token, 'dashboard_consultar_personal', ['operacion' => 'contar']);
            $this->assertTrue($isError, $key);
            $this->assertSame(__('mcp.dashboard.'.$key), $text);
            $this->assertStringNotContainsString('cURL', $text);
        }
    }

    public function test_without_the_bridge_secret_there_are_no_dashboard_tools(): void
    {
        config(['ecosistema.puente.secreto_salida' => null]);
        $this->fakeDashboard();
        [, $token] = $this->client('lector', McpClient::DASHBOARD_READ);

        $this->assertNotContains('dashboard_consultar_personal', $this->toolNames($token));
        Http::assertNothingSent();
    }

    // ---- Comandos ----

    public function test_client_names_are_limited_to_what_the_dashboard_accepts(): void
    {
        foreach (['mal<nombre>', 'con/barra', 'acentó', str_repeat('a', 81), ''] as $name) {
            $this->artisan('mcp:cliente-crear', ['nombre' => $name, 'correo' => $this->account->email])->assertFailed();
        }

        $this->artisan('mcp:cliente-crear', ['nombre' => 'grokbot-lectura 2.0', 'correo' => $this->account->email])->assertSuccessful();
        $this->assertSame(1, McpClient::query()->count());
    }

    public function test_creating_a_client_with_dashboard_access_validates_the_level(): void
    {
        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $this->account->email, '--dashboard' => 'todo'])->assertFailed();
        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $this->account->email, '--dashboard' => 'write'])->assertFailed();
        $this->assertSame(0, McpClient::query()->count());

        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $this->account->email, '--dashboard' => 'read'])->assertSuccessful();
        $this->artisan('mcp:cliente-crear', ['nombre' => 'b', 'correo' => $this->account->email, '--escritura' => true, '--dashboard' => 'write'])->assertSuccessful();

        $this->assertSame('read', McpClient::query()->where('name', 'a')->sole()->dashboard_access);
        $this->assertSame('write', McpClient::query()->where('name', 'b')->sole()->dashboard_access);
    }

    public function test_the_dashboard_command_changes_the_level_audits_it_and_protects_read_only_clients(): void
    {
        [$readOnly] = $this->client('lector', McpClient::DASHBOARD_NONE);
        [$writer] = $this->client('escritor', McpClient::DASHBOARD_NONE, true);

        $this->artisan('mcp:cliente-dashboard', ['nombre' => 'lector', 'nivel' => 'write'])->assertFailed();
        $this->artisan('mcp:cliente-dashboard', ['nombre' => 'lector', 'nivel' => 'read'])->assertSuccessful();
        $this->artisan('mcp:cliente-dashboard', ['nombre' => 'escritor', 'nivel' => 'write'])->assertSuccessful();
        $this->artisan('mcp:cliente-dashboard', ['nombre' => 'escritor', 'nivel' => 'cualquiera'])->assertFailed();
        $this->artisan('mcp:cliente-dashboard', ['nombre' => 'no-existe', 'nivel' => 'read'])->assertFailed();

        $this->assertSame('read', $readOnly->refresh()->dashboard_access);
        $this->assertSame('write', $writer->refresh()->dashboard_access);
        $this->assertSame(2, ActivityLogEntry::query()->where('log_name', 'mcp')->where('event', 'client_dashboard_changed')->count());

        $writer->forceFill(['revoked_at' => now()])->save();
        $this->artisan('mcp:cliente-dashboard', ['nombre' => 'escritor', 'nivel' => 'none'])->assertFailed();
    }
}
