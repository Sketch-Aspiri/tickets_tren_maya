<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Coordination;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/**
 * Puente con el portal (decision 99): firma con secreto propio (distinto del del Dashboard), catalogo de
 * equipos, alta de cuentas aprobadas (misma contrasena, vinculada, con equipo) y listado sin datos sensibles.
 */
class PortalBridgeTest extends DatabaseTestCase
{
    private const SECRET = 'secreto-portal-a-tickets-de-prueba-0123456789ab';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.puente.portal.secreto' => self::SECRET,
            'ecosistema.puente.secreto_entrada' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.ips' => [],
        ]);
    }

    /** @return array<string, string> */
    private function signed(string $method, string $path, string $body = '', array $query = [], string $secret = self::SECRET, string $system = 'portal', ?int $date = null, ?string $nonce = null): array
    {
        $date ??= now()->getTimestamp();
        $nonce ??= Str::random(40);
        ksort($query);
        $canonical = implode("\n", [$method, $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $system, (string) $date, $nonce, hash('sha256', $body)]);

        return [
            'X-Puente-Sistema' => $system,
            'X-Puente-Fecha' => (string) $date,
            'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonical, $secret),
        ];
    }

    /** GET sin cuerpo, como lo envia el portal (getJson() manda "[]" y cambiaria el hash firmado). */
    private function getSigned(string $path, array $query = [], array $headers = []): TestResponse
    {
        $headers = $headers ?: $this->signed('GET', $path, query: $query);
        $uri = $path.($query === [] ? '' : '?'.http_build_query($query));

        return $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json']));
    }

    private function postAccount(array $payload, array $headerOverrides = []): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/interno/portal/cuentas', [], [], [], $this->transformHeadersToServerVars(
            ($headerOverrides ?: $this->signed('POST', '/interno/portal/cuentas', $body)) + ['CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json'],
        ), $body);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'portal_user_id' => (string) Str::uuid(),
            'name' => 'Ana López',
            'email' => 'ana@ejemplo.test',
            'password_hash' => Hash::make('Clave-Segura-2026!', ['rounds' => 10]),
            'role' => 'empleado',
            'team_ids' => [Team::factory()->create()->id],
        ];
    }

    public function test_requests_without_a_valid_portal_signature_are_rejected(): void
    {
        $this->getJson('/interno/portal/catalogo')->assertUnauthorized();

        // Secreto equivocado, sistema equivocado y secreto del Dashboard (otro canal).
        $this->getSigned('/interno/portal/catalogo', headers: $this->signed('GET', '/interno/portal/catalogo', secret: 'otro-secreto-de-al-menos-treinta-y-dos-caracteres'))->assertUnauthorized();
        $this->getSigned('/interno/portal/catalogo', headers: $this->signed('GET', '/interno/portal/catalogo', system: 'dashboard'))->assertUnauthorized();
        $this->getSigned('/interno/portal/catalogo', headers: $this->signed('GET', '/interno/portal/catalogo', secret: 'secreto-dashboard-a-tickets-de-prueba-0123456789'))->assertUnauthorized();
        // Fecha fuera de la ventana.
        $this->getSigned('/interno/portal/catalogo', headers: $this->signed('GET', '/interno/portal/catalogo', date: now()->subMinutes(30)->getTimestamp()))->assertUnauthorized();
    }

    public function test_the_dashboard_secret_does_not_open_the_portal_channel_and_vice_versa(): void
    {
        // La firma del portal tampoco vale en el canal del Dashboard.
        $this->getSigned('/interno/pendientes', ['correo' => 'a@ejemplo.test'])->assertUnauthorized();
    }

    public function test_a_nonce_cannot_be_replayed(): void
    {
        $headers = $this->signed('GET', '/interno/portal/catalogo');

        $this->getSigned('/interno/portal/catalogo', headers: $headers)->assertOk();
        $this->getSigned('/interno/portal/catalogo', headers: $headers)->assertUnauthorized();
    }

    public function test_without_a_configured_secret_the_portal_routes_do_not_exist(): void
    {
        config(['ecosistema.puente.portal.secreto' => null]);

        $this->getSigned('/interno/portal/catalogo')->assertNotFound();
    }

    public function test_the_catalog_lists_roles_teams_and_coordinations(): void
    {
        $team = Team::factory()->create(['name' => 'Soporte TI']);

        $response = $this->getSigned('/interno/portal/catalogo')->assertOk();

        $response->assertJsonPath('sistema', 'tickets')
            ->assertJsonFragment(['clave' => 'empleado', 'requiere_equipo' => true])
            ->assertJsonFragment(['clave' => 'jefe_zona', 'requiere_coordinaciones' => true])
            ->assertJsonFragment(['id' => $team->id, 'nombre' => 'Soporte TI']);
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_an_account_is_created_active_linked_with_the_same_password_and_its_team(): void
    {
        $payload = $this->payload(['email' => 'ana@ejemplo.test', 'role' => 'coordinador']);

        $this->postAccount($payload)->assertOk()->assertJsonPath('estado', 'creada');

        $user = User::where('email', 'ana@ejemplo.test')->firstOrFail();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame($payload['portal_user_id'], $user->portal_user_id);
        $this->assertNotNull($user->sso_linked_at);
        $this->assertSame(UserRole::Coordinador, $user->roleEnum());
        $this->assertEqualsCanonicalizing($payload['team_ids'], $user->teams()->pluck('teams.id')->all());
        // El hash llega tal cual (no se vuelve a cifrar): la contrasena del portal funciona aqui.
        $this->assertSame($payload['password_hash'], $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('Clave-Segura-2026!', $user->password));
        $this->assertDatabaseHas('activity_log', ['event' => 'provisioned_by_portal', 'subject_id' => $user->id]);
    }

    public function test_a_chief_gets_coordinations_and_an_administrator_needs_neither(): void
    {
        $coordination = Coordination::factory()->create();

        $this->postAccount($this->payload(['email' => 'jefe@ejemplo.test', 'role' => 'jefe_zona', 'team_ids' => [], 'coordination_ids' => [$coordination->id]]))
            ->assertOk();
        $this->postAccount($this->payload(['email' => 'admin@ejemplo.test', 'role' => 'administrador', 'team_ids' => []]))->assertOk();

        $chief = User::where('email', 'jefe@ejemplo.test')->firstOrFail();
        $this->assertSame([$coordination->id], $chief->coordinations()->pluck('coordinations.id')->all());
        $this->assertSame(UserRole::Administrador, User::where('email', 'admin@ejemplo.test')->firstOrFail()->roleEnum());
    }

    public function test_the_same_request_twice_is_idempotent(): void
    {
        $payload = $this->payload();

        $this->postAccount($payload)->assertOk()->assertJsonPath('estado', 'creada');
        $this->postAccount($payload)->assertOk()->assertJsonPath('estado', 'ya_aprovisionada');

        $this->assertSame(1, User::where('email', 'ana@ejemplo.test')->count());
    }

    public function test_an_existing_account_with_that_email_is_never_touched_or_paired(): void
    {
        $existing = User::factory()->empleado()->create(['email' => 'ANA@ejemplo.test']);
        $oldHash = $existing->getRawOriginal('password');

        $this->postAccount($this->payload(['email' => 'ana@ejemplo.test']))->assertStatus(409)->assertJsonPath('estado', 'correo_existente');

        $existing->refresh();
        $this->assertNull($existing->portal_user_id);
        $this->assertNull($existing->sso_linked_at);
        $this->assertSame($oldHash, $existing->getRawOriginal('password'));
    }

    public function test_the_payload_is_validated(): void
    {
        $team = Team::factory()->create();

        $this->postAccount($this->payload(['role' => 'dios']))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postAccount($this->payload(['role' => 'empleado', 'team_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('team_ids');
        $this->postAccount($this->payload(['role' => 'jefe_zona', 'team_ids' => [], 'coordination_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('coordination_ids');
        $this->postAccount($this->payload(['team_ids' => [999999]]))->assertUnprocessable();
        $this->postAccount($this->payload(['password_hash' => 'Clave-Segura-2026!', 'team_ids' => [$team->id]]))->assertUnprocessable()->assertJsonValidationErrors('password_hash');
        $this->postAccount($this->payload(['email' => 'Mayus@Ejemplo.test', 'team_ids' => [$team->id]]))->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_tampered_body_does_not_pass_the_signature(): void
    {
        $payload = $this->payload();
        $headers = $this->signed('POST', '/interno/portal/cuentas', (string) json_encode($payload));

        // Se firma un cuerpo y se envia otro (otro rol).
        $this->postAccount(array_replace($payload, ['role' => 'administrador', 'team_ids' => []]), $headers)->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_listing_is_paged_and_has_no_sensitive_data(): void
    {
        User::factory()->empleado()->create(['email' => 'uno@ejemplo.test', 'sso_linked_at' => now()]);
        User::factory()->empleado()->create(['email' => 'dos@ejemplo.test']);
        User::factory()->empleado()->create(['email' => 'sistema@sistema.invalid']);

        $response = $this->getSigned('/interno/portal/cuentas', ['limite' => 1])->assertOk();

        $response->assertJsonCount(1, 'cuentas')->assertJsonPath('cuentas.0.email', 'uno@ejemplo.test')->assertJsonPath('cuentas.0.vinculada', true);
        $this->assertNotNull($response->json('siguiente'));
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('two_factor', $response->getContent());

        $next = $this->getSigned('/interno/portal/cuentas', ['despues' => $response->json('siguiente'), 'limite' => 500])->assertOk();
        $this->assertSame(['dos@ejemplo.test'], array_column($next->json('cuentas'), 'email'));
        $this->assertNull($next->json('siguiente'));
    }
}
