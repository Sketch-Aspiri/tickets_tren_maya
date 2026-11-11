<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Ecosystem\EcosystemSignature;
use App\Services\Ecosystem\SingleSignOnService;
use App\Services\TwoFactorService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/**
 * Inicio y cierre de sesion unicos con el Dashboard (decision 88): codigos firmados de un solo uso atados
 * al navegador, cuentas vinculadas una sola vez con contrasena, 2FA heredado, cuentas inactivas o sin rol
 * excluidas y sin ciclos de redireccion.
 */
class EcosystemSingleSignOnTest extends DatabaseTestCase
{
    private const SECRET = 'secreto-de-prueba-de-al-menos-32-caracteres-xx';

    private const STATE = 'EstadoDePrueba0123456789abcdefghijABCDEF';

    private bool $dashboardUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.sso.secreto' => self::SECRET,
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
        ]);
        Http::fake(['https://dashboard.ejemplo.test/up' => fn () => Http::response('', $this->dashboardUp ? 200 : 500)]);
    }

    /**
     * Codigo como lo firmaria el Dashboard (mismo formato y secreto).
     */
    private function dashboardCode(string $email, bool $mfa = true, string $action = EcosystemSignature::ENTER, string $aud = 'tickets', int $exp = 60, ?string $state = self::STATE, bool $linked = true): string
    {
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $payload = $b64((string) json_encode(['v' => 1, 'iss' => 'dashboard', 'aud' => $aud, 'act' => $action, 'email' => $email, 'mfa' => $mfa, 'state' => $state, 'lnk' => $linked, 'nonce' => bin2hex(random_bytes(16)), 'exp' => now()->addSeconds($exp)->getTimestamp()]));

        return $payload.'.'.$b64(hash_hmac('sha256', $payload, self::SECRET, true));
    }

    /**
     * Llega la respuesta del Dashboard al navegador que hizo la pregunta.
     */
    private function enterWith(string $code): TestResponse
    {
        return $this->withSession([SingleSignOnService::SESSION_STATE => self::STATE])
            ->get(route('ecosistema.entrar', ['codigo' => $code]));
    }

    public function test_login_asks_the_dashboard_once_with_a_state_then_shows_the_form(): void
    {
        $location = (string) $this->get(route('login'))->assertRedirect()->headers->get('Location');

        $this->assertMatchesRegularExpression('#^https://dashboard\.ejemplo\.test/ecosistema/sso\?para=tickets&estado=[A-Za-z0-9]{40}$#', $location);
        $this->assertSame(substr($location, -40), session(SingleSignOnService::SESSION_STATE));
        $this->get(route('login'))->assertOk()->assertSee('name="password"', false);
        $this->get(route('login', ['local' => 1]))->assertOk();
    }

    public function test_dashboard_down_or_no_secret_shows_the_local_login(): void
    {
        $this->dashboardUp = false;
        $this->get(route('login'))->assertOk();

        $this->dashboardUp = true;
        config(['ecosistema.sso.secreto' => null]);
        $this->get(route('login'))->assertOk();
    }

    public function test_a_linked_account_signs_in_and_inherits_two_factor(): void
    {
        $jefe = User::factory()->jefe()->withTwoFactor()->create(['email' => 'jefe@ejemplo.test', 'sso_linked_at' => now()]);

        $this->enterWith($this->dashboardCode('JEFE@ejemplo.test'))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($jefe);
        $this->assertSame($jefe->getKey(), session(TwoFactorService::SESSION_KEY));
        $this->get(route('dashboard'))->assertOk();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'sso_login', 'causer_id' => $jefe->id]);
    }

    public function test_without_two_factor_at_the_origin_it_is_asked_here(): void
    {
        $jefe = User::factory()->jefe()->withTwoFactor()->create(['sso_linked_at' => now()]);

        $this->enterWith($this->dashboardCode($jefe->email, mfa: false));

        $this->assertAuthenticatedAs($jefe);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_a_code_requested_by_another_browser_does_not_sign_in(): void
    {
        $user = User::factory()->empleado()->create(['sso_linked_at' => now()]);

        $this->get(route('ecosistema.entrar', ['codigo' => $this->dashboardCode($user->email)]))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->enterWith($this->dashboardCode($user->email, state: str_repeat('z', 40)))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_tampered_expired_replayed_or_misdirected_codes_are_rejected(): void
    {
        $user = User::factory()->empleado()->create(['sso_linked_at' => now()]);
        $good = $this->dashboardCode($user->email);

        foreach ([
            substr($good, 0, -2).'xx',
            $this->dashboardCode($user->email, exp: -5),
            $this->dashboardCode($user->email, aud: 'dashboard'),
            $this->dashboardCode($user->email, action: EcosystemSignature::LEAVE),
        ] as $bad) {
            $this->enterWith($bad)->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $this->enterWith($good);
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->enterWith($good)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_first_time_the_account_is_linked_with_the_local_password_and_never_again(): void
    {
        $user = User::factory()->empleado()->create(['email' => 'persona@ejemplo.test']);

        $this->enterWith($this->dashboardCode($user->email))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('auth.ecosystem.link_first'));
        $this->assertGuest();

        $this->post(route('login'), ['email' => $user->email, 'password' => UserFactory::DEFAULT_PASSWORD]);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->sso_linked_at);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'sso_linked', 'causer_id' => $user->id]);

        auth()->logout();
        $this->enterWith($this->dashboardCode($user->email));
        $this->assertAuthenticatedAs($user);
    }

    public function test_after_a_completed_round_trip_the_next_login_asks_again_without_waiting_minutes(): void
    {
        $this->get(route('login'))->assertRedirect();

        // The other system answers (here «no session»): the immediate visit does not ask again...
        $this->get(route('ecosistema.entrar', ['sin_sesion' => 1]))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('name="password"', false);

        // ...but the next one DOES (before it stayed blocked for 5 minutes and someone already signed in at the
        // Dashboard typed their password here).
        $this->get(route('login'))->assertRedirect();
    }

    public function test_if_the_other_system_never_answers_the_plain_login_stays_available(): void
    {
        $this->get(route('login'))->assertRedirect();

        $this->get(route('login'))->assertOk()->assertSee('name="password"', false);
        $this->get(route('login'))->assertOk();
    }

    public function test_after_logging_out_here_only_the_immediate_login_visit_skips_the_question(): void
    {
        $this->signIn(User::factory()->empleado()->create());

        $this->post(route('logout'))->assertOk();

        $this->get(route('login'))->assertOk();       // the one right after the logout
        $this->get(route('login'))->assertRedirect(); // afterwards it asks the Dashboard again
    }

    public function test_a_logout_coming_from_the_dashboard_does_not_stop_asking_afterwards(): void
    {
        $user = User::factory()->empleado()->create();
        $this->signIn($user);

        $this->get(route('ecosistema.salir', ['codigo' => $this->dashboardCode($user->email, action: EcosystemSignature::LEAVE, state: null)]))->assertRedirect();
        $this->assertGuest();

        // Before, this logout left the login without asking for 5 minutes even if the Dashboard had a session.
        $this->get(route('login'))->assertRedirect();
    }

    public function test_with_two_factor_the_link_waits_for_the_challenge(): void
    {
        $jefe = User::factory()->jefe()->withTwoFactor()->create(['email' => 'jefe@ejemplo.test']);

        $this->enterWith($this->dashboardCode($jefe->email))->assertRedirect(route('login'));

        // La contrasena sola NO vincula: quien la robo no tiene el 2FA de la persona (decision 90).
        $this->post(route('login'), ['email' => $jefe->email, 'password' => UserFactory::DEFAULT_PASSWORD]);
        $this->assertAuthenticatedAs($jefe);
        $this->assertNull($jefe->fresh()->sso_linked_at);

        $this->post(route('two-factor.verify'), ['code' => '000000']);
        $this->assertNull($jefe->fresh()->sso_linked_at);

        $this->post(route('two-factor.verify'), ['code' => $this->validOtp($jefe)])->assertSessionHasNoErrors();
        $this->assertNotNull($jefe->fresh()->sso_linked_at);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'sso_linked', 'causer_id' => $jefe->id]);

        auth()->logout();
        $this->enterWith($this->dashboardCode($jefe->email));
        $this->assertAuthenticatedAs($jefe);
    }

    public function test_an_origin_without_a_link_does_not_sign_in_without_the_password_even_if_this_account_is_linked(): void
    {
        $user = User::factory()->empleado()->create(['sso_linked_at' => now()]);

        // En el Dashboard el correo se pudo editar sin verificar: ese codigo no basta.
        $this->enterWith($this->dashboardCode($user->email, linked: false))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('auth.ecosystem.link_first'));
        $this->assertGuest();
    }

    public function test_a_code_without_the_link_claim_is_treated_as_unlinked(): void
    {
        $user = User::factory()->empleado()->create(['sso_linked_at' => now()]);
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $payload = $b64((string) json_encode(['v' => 1, 'iss' => 'dashboard', 'aud' => 'tickets', 'act' => 'entrar', 'email' => $user->email, 'mfa' => true, 'state' => self::STATE, 'nonce' => bin2hex(random_bytes(16)), 'exp' => now()->addSeconds(60)->getTimestamp()]));

        $this->enterWith($payload.'.'.$b64(hash_hmac('sha256', $payload, self::SECRET, true)))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_this_system_signs_with_the_link_state_of_the_account(): void
    {
        $sign = function (User $user): array {
            $this->signIn($user);
            $location = (string) $this->get(route('ecosistema.sso', ['para' => 'dashboard', 'estado' => self::STATE]))->assertRedirect()->headers->get('Location');
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            [$payload] = explode('.', (string) $query['codigo']);

            return json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        };

        $this->assertFalse($sign(User::factory()->empleado()->create())['lnk']);
        $this->assertTrue($sign(User::factory()->empleado()->create(['sso_linked_at' => now()]))['lnk']);
    }

    public function test_signing_in_with_another_account_does_not_link_the_signed_one(): void
    {
        $victim = User::factory()->empleado()->create();
        $other = User::factory()->empleado()->create();

        $this->enterWith($this->dashboardCode($victim->email));
        $this->post(route('login'), ['email' => $other->email, 'password' => UserFactory::DEFAULT_PASSWORD]);

        $this->assertNull($victim->fresh()->sso_linked_at);
        $this->assertNull($other->fresh()->sso_linked_at);
    }

    public function test_changing_the_email_breaks_the_link(): void
    {
        $user = User::factory()->empleado()->create(['sso_linked_at' => now()]);

        $user->update(['email' => 'nuevo@ejemplo.test']);

        $this->assertNull($user->fresh()->sso_linked_at);
    }

    public function test_unknown_pending_or_roleless_accounts_do_not_sign_in(): void
    {
        $this->enterWith($this->dashboardCode('solo-dashboard@ejemplo.test'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();

        $pending = User::factory()->pending()->create(['sso_linked_at' => now()]);
        $this->enterWith($this->dashboardCode($pending->email));
        $this->assertGuest();
    }

    public function test_only_a_complete_session_is_shared(): void
    {
        $this->get(route('ecosistema.sso', ['para' => 'dashboard', 'estado' => self::STATE]))
            ->assertRedirect('https://dashboard.ejemplo.test/ecosistema/entrar?sin_sesion=1');

        // Jefe con 2FA configurado pero sin pasar el desafio en esta sesion: no se comparte.
        $this->actingAs(User::factory()->jefe()->withTwoFactor()->create());
        $this->get(route('ecosistema.sso', ['para' => 'dashboard', 'estado' => self::STATE]))
            ->assertRedirect('https://dashboard.ejemplo.test/ecosistema/entrar?sin_sesion=1');
    }

    public function test_a_complete_session_answers_with_a_code_carrying_the_state(): void
    {
        $this->signIn(User::factory()->jefe()->withTwoFactor()->create(['email' => 'jefe@ejemplo.test']));

        $location = (string) $this->get(route('ecosistema.sso', ['para' => 'dashboard', 'estado' => self::STATE]))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://dashboard.ejemplo.test/ecosistema/entrar?codigo=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        [$payload] = explode('.', (string) $query['codigo']);
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame(['dashboard', 'entrar', 'jefe@ejemplo.test', true, self::STATE, false], [$data['aud'], $data['act'], $data['email'], $data['mfa'], $data['state'], $data['lnk']]);
    }

    public function test_only_known_systems_are_answered(): void
    {
        $this->signIn(User::factory()->empleado()->create());

        $this->get(route('ecosistema.sso', ['para' => 'https://malo.test']))->assertSessionHasErrors('para');
    }

    public function test_logging_out_here_also_logs_out_of_the_dashboard_without_breaking_the_csp(): void
    {
        $this->signIn(User::factory()->empleado()->create());

        $response = $this->post(route('logout'))->assertOk();

        $this->assertGuest();
        $this->assertMatchesRegularExpression('#<meta http-equiv="refresh" content="0;url=https://dashboard\.ejemplo\.test/ecosistema/salir\?codigo=#', (string) $response->getContent());
        $this->assertStringNotContainsString('<script', (string) $response->getContent());
        $this->get(route('login'))->assertOk();
    }

    public function test_a_logout_from_the_dashboard_only_logs_out_the_same_person(): void
    {
        $user = User::factory()->empleado()->create();
        $this->signIn($user);

        $this->get(route('ecosistema.salir', ['codigo' => $this->dashboardCode('otra@ejemplo.test', action: EcosystemSignature::LEAVE, state: null)]))
            ->assertRedirect('https://dashboard.ejemplo.test/login');
        $this->assertAuthenticatedAs($user);

        $this->get(route('ecosistema.salir', ['codigo' => $this->dashboardCode($user->email, action: EcosystemSignature::LEAVE, state: null)]))
            ->assertRedirect('https://dashboard.ejemplo.test/login');
        $this->assertGuest();
    }
}
