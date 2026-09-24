<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TwoFactorService;
use Database\Factories\UserFactory;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class AuthenticationTest extends DatabaseTestCase
{
    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_guest_is_redirected_to_login_from_protected_routes(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/profile')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
        $this->get('/teams')->assertRedirect('/login');
        $this->get('/account/status')->assertRedirect('/login');
    }

    public function test_guest_receives_401_json_on_protected_routes(): void
    {
        $this->getJson('/dashboard')->assertUnauthorized();
    }

    public function test_active_empleado_can_login_and_session_id_is_regenerated(): void
    {
        $user = User::factory()->empleado()->create();

        $this->startSession();
        $before = session()->getId();

        $response = $this->post('/login', ['email' => $user->email, 'password' => UserFactory::DEFAULT_PASSWORD]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
    }

    public function test_login_clears_previous_two_factor_verification(): void
    {
        $user = User::factory()->empleado()->withTwoFactor()->create();

        $this->withSession([TwoFactorService::SESSION_KEY => $user->id])
            ->post('/login', ['email' => $user->email, 'password' => UserFactory::DEFAULT_PASSWORD]);

        $this->assertNull(session(TwoFactorService::SESSION_KEY));
    }

    public function test_email_is_case_insensitive_on_login(): void
    {
        $user = User::factory()->empleado()->create(['email' => 'mixed@example.com']);

        $this->post('/login', ['email' => 'MIXED@Example.com', 'password' => UserFactory::DEFAULT_PASSWORD]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_fails_and_is_logged_without_the_password(): void
    {
        $user = User::factory()->empleado()->create();

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-Passw0rd!']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        $activity = Activity::query()->where('event', 'login_failed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($user->email, $activity->properties['email']);
        $this->assertStringNotContainsString('Wrong-Passw0rd!', $activity->properties->toJson());
        $this->assertStringNotContainsString('password', $activity->properties->toJson());
    }

    public function test_unknown_email_fails_generically(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'Whatever-123'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_login_and_logout_are_recorded_in_the_activity_log(): void
    {
        $user = User::factory()->empleado()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::DEFAULT_PASSWORD]);
        $this->post('/logout');

        $events = Activity::query()->where('causer_id', $user->id)->pluck('event')->all();
        $this->assertContains('login', $events);
        $this->assertContains('logout', $events);
    }

    public function test_logout_invalidates_the_session_and_redirects_to_login(): void
    {
        $user = User::factory()->empleado()->create();

        $response = $this->signIn($user)->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session(TwoFactorService::SESSION_KEY));
    }

    public function test_login_is_rate_limited_with_429(): void
    {
        $user = User::factory()->empleado()->create();
        $limit = (int) config('tickets.rate_limits.login_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-Passw0rd!']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::DEFAULT_PASSWORD])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_login_validates_required_fields(): void
    {
        $this->post('/login', [])->assertInvalid(['email', 'password']);
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'x'])->assertInvalid(['email']);
    }
}
