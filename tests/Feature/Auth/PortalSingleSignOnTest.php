<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Ecosystem\EcosystemSignature;
use App\Services\Ecosystem\SingleSignOnService;
use Illuminate\Support\Facades\Http;
use Tests\DatabaseTestCase;

/**
 * El portal (zonaorientetm.tech) emite el inicio y cierre de sesión únicos con los mismos códigos firmados que
 * se usaban entre Tickets y el Dashboard. Si ECOSISTEMA_PORTAL_URL está definido, el portal es el origen.
 */
class PortalSingleSignOnTest extends DatabaseTestCase
{
    private const SECRET = 'secreto-de-prueba-de-al-menos-32-caracteres-xx';

    private const STATE = 'EstadoDePrueba0123456789abcdefghijABCDEF';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.sso.secreto' => self::SECRET,
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.urls.portal' => 'https://portal.ejemplo.test',
        ]);
        Http::fake(['https://portal.ejemplo.test/up' => Http::response('', 200)]);
    }

    /** Código como lo firma el portal (mismo formato y secreto). */
    private function portalCode(string $email, string $action = EcosystemSignature::ENTER, bool $mfa = true, bool $linked = true): string
    {
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $payload = $b64((string) json_encode([
            'v' => 1, 'iss' => 'portal', 'aud' => 'tickets', 'act' => $action, 'email' => $email, 'mfa' => $mfa,
            'state' => self::STATE, 'lnk' => $linked, 'nonce' => bin2hex(random_bytes(16)),
            'exp' => now()->addSeconds(60)->getTimestamp(),
        ]));

        return $payload.'.'.$b64(hash_hmac('sha256', $payload, self::SECRET, true));
    }

    public function test_login_asks_the_portal_instead_of_the_dashboard_when_it_is_configured(): void
    {
        $location = (string) $this->get(route('login'))->assertRedirect()->headers->get('Location');

        $this->assertMatchesRegularExpression('#^https://portal\.ejemplo\.test/ecosistema/sso\?para=tickets&estado=[A-Za-z0-9]{40}$#', $location);
    }

    public function test_a_portal_code_signs_in_a_linked_account_and_inherits_two_factor(): void
    {
        $jefe = User::factory()->jefe()->withTwoFactor()->create(['email' => 'jefe@ejemplo.test', 'sso_linked_at' => now()]);

        $this->withSession([SingleSignOnService::SESSION_STATE => self::STATE])
            ->get(route('ecosistema.entrar', ['codigo' => $this->portalCode('JEFE@ejemplo.test')]))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($jefe);
        $this->get(route('dashboard'))->assertOk();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'sso_login', 'causer_id' => $jefe->id]);
    }

    public function test_a_portal_code_does_not_sign_in_an_account_not_linked_here_yet(): void
    {
        User::factory()->jefe()->withTwoFactor()->create(['email' => 'jefe@ejemplo.test', 'sso_linked_at' => null]);

        $this->withSession([SingleSignOnService::SESSION_STATE => self::STATE])
            ->get(route('ecosistema.entrar', ['codigo' => $this->portalCode('jefe@ejemplo.test')]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_portal_leave_code_logs_out_the_same_person_and_returns_to_the_portal_login(): void
    {
        $jefe = User::factory()->jefe()->withTwoFactor()->create(['email' => 'jefe@ejemplo.test', 'sso_linked_at' => now()]);

        $this->actingAs($jefe)
            ->get(route('ecosistema.salir', ['codigo' => $this->portalCode('jefe@ejemplo.test', EcosystemSignature::LEAVE, false)]))
            ->assertRedirect('https://portal.ejemplo.test/login');

        $this->assertGuest();
    }

    public function test_without_the_portal_configured_the_dashboard_stays_the_origin(): void
    {
        config(['ecosistema.urls' => [
            'dashboard' => 'https://dashboard.ejemplo.test',
            'tickets' => 'https://tickets.ejemplo.test',
        ]]);
        Http::fake(['https://dashboard.ejemplo.test/up' => Http::response('', 200)]);

        $this->assertSame('dashboard', app(SingleSignOnService::class)->sibling());
    }
}
