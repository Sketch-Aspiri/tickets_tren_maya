<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\SessionInvalidator;
use App\Services\UserService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\UsesDatabaseSessions;
use Tests\DatabaseTestCase;

class SessionInvalidationTest extends DatabaseTestCase
{
    use UsesDatabaseSessions;

    private const NEW_PASSWORD = 'Brand-New-Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useDatabaseSessions();
    }

    private function sessionCount(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->count();
    }

    public function test_session_middleware_drops_sessions_whose_password_hash_is_stale(): void
    {
        $user = User::factory()->empleado()->create();
        $cookie = $this->loginViaHttp($user);
        $this->asSession($cookie)->get('/dashboard')->assertOk();

        // Cambio de contrasena por fuera de los servicios: solo AuthenticateSession puede detectarlo.
        $user->forceFill(['password' => Hash::make('Another-Passw0rd-1')])->save();

        $this->asSession($cookie)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_changing_password_ends_other_sessions_and_keeps_the_current_one(): void
    {
        $user = User::factory()->empleado()->create();
        $oldDevice = $this->loginViaHttp($user);
        $currentDevice = $this->loginViaHttp($user);
        $this->assertSame(2, $this->sessionCount($user));

        $this->asSession($currentDevice)->put('/password', [
            'current_password' => 'Password-12345',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->sessionCount($user));
        $this->assertNotNull($user->fresh()->remember_token);
        $this->asSession($currentDevice)->get('/dashboard')->assertOk();
        $this->asSession($oldDevice)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_changing_password_rotates_the_remember_token(): void
    {
        $user = User::factory()->empleado()->create(['remember_token' => 'old-remember-token']);
        $device = $this->loginViaHttp($user);

        $this->asSession($device)->put('/password', [
            'current_password' => 'Password-12345',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $token = $user->fresh()->remember_token;
        $this->assertNotSame('old-remember-token', $token);
        $this->assertSame(60, strlen((string) $token));
    }

    public function test_resetting_password_ends_every_session_and_rotates_the_remember_token(): void
    {
        Notification::fake();
        $user = User::factory()->empleado()->create(['remember_token' => 'old-remember-token']);
        $oldDevice = $this->loginViaHttp($user);

        $this->resetClientState();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertSame(0, $this->sessionCount($user));
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->asSession($oldDevice)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_deactivation_revokes_sessions_and_reactivation_does_not_resurrect_them(): void
    {
        $jefe = User::factory()->jefe()->create();
        $empleado = User::factory()->empleado()->create(['remember_token' => 'old-remember-token']);
        $device = $this->loginViaHttp($empleado);
        $service = app(UserService::class);

        $service->deactivate($jefe, $empleado);

        $this->assertSame(0, $this->sessionCount($empleado));
        $this->assertNotSame('old-remember-token', $empleado->fresh()->remember_token);

        $service->activate($jefe, $empleado->fresh());

        $this->asSession($device)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_two_factor_verification_does_not_survive_deactivation_and_reactivation(): void
    {
        $jefe = User::factory()->jefe()->create();
        $coordinador = User::factory()->coordinador()->create();

        $loginCookie = $this->loginViaHttp($coordinador);
        $device = $this->sessionCookieFrom(
            $this->asSession($loginCookie)
                ->post('/two-factor/challenge', ['code' => $this->validOtp($coordinador)])
                ->assertSessionHasNoErrors(),
        );
        $this->asSession($device)->get('/dashboard')->assertOk();

        app(UserService::class)->deactivate($jefe, $coordinador);
        app(UserService::class)->activate($jefe, $coordinador->fresh());

        $this->asSession($device)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_rejecting_a_pending_account_revokes_its_sessions(): void
    {
        $jefe = User::factory()->jefe()->create();
        $pending = User::factory()->pending()->create(['remember_token' => 'old-remember-token']);
        $this->loginViaHttp($pending);
        $this->assertSame(1, $this->sessionCount($pending));

        app(UserService::class)->reject($jefe, $pending);

        $this->assertSame(0, $this->sessionCount($pending));
        $this->assertNotSame('old-remember-token', $pending->fresh()->remember_token);
    }

    public function test_activating_revokes_sessions_created_while_the_account_was_inactive(): void
    {
        $jefe = User::factory()->jefe()->create();
        $inactive = User::factory()->empleado()->inactive()->create();
        $device = $this->loginViaHttp($inactive);
        $this->assertSame(1, $this->sessionCount($inactive));

        app(UserService::class)->activate($jefe, $inactive);

        $this->assertSame(0, $this->sessionCount($inactive));
        $this->asSession($device)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_revoking_sessions_of_one_user_never_touches_another_users_sessions(): void
    {
        $alice = User::factory()->empleado()->create();
        $bob = User::factory()->empleado()->create();
        $this->loginViaHttp($alice);
        $this->loginViaHttp($bob);

        app(SessionInvalidator::class)->revokeAll($alice);

        $this->assertSame(0, $this->sessionCount($alice));
        $this->assertSame(1, $this->sessionCount($bob));
    }

    public function test_revoking_is_a_safe_noop_for_non_database_session_drivers(): void
    {
        config(['session.driver' => 'array']);
        $user = User::factory()->empleado()->create(['remember_token' => 'old-remember-token']);

        app(SessionInvalidator::class)->revokeAll($user);

        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
    }
}
