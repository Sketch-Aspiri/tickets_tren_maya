<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class PasswordResetTest extends DatabaseTestCase
{
    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_reset_password_link_can_be_requested_and_response_is_uniform(): void
    {
        Notification::fake();
        $user = User::factory()->empleado()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'ghost@example.com']);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
        $known->assertSessionHas('status', __('passwords.sent'));
        $unknown->assertSessionHas('status', __('passwords.sent'));
    }

    public function test_reset_password_screen_can_be_rendered_and_password_can_be_reset(): void
    {
        Notification::fake();
        $user = User::factory()->empleado()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->get('/reset-password/'.$notification->token)->assertOk();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Brand-New-Passw0rd',
                'password_confirmation' => 'Brand-New-Passw0rd',
            ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('Brand-New-Passw0rd', $user->fresh()->password));
        $this->assertDatabaseHas('activity_log', ['event' => 'password_reset', 'causer_id' => $user->id]);
    }

    public function test_reset_rejects_weak_password_and_bad_token(): void
    {
        Notification::fake();
        $user = User::factory()->empleado()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ])->assertInvalid(['password']);

            $this->post('/reset-password', [
                'token' => 'invalid-token',
                'email' => $user->email,
                'password' => 'Brand-New-Passw0rd',
                'password_confirmation' => 'Brand-New-Passw0rd',
            ])->assertInvalid(['email']);

            return true;
        });

        $this->assertTrue(Hash::check('Password-12345', $user->fresh()->password));
    }

    public function test_password_reset_request_is_rate_limited(): void
    {
        $limit = (int) config('tickets.rate_limits.password_reset_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/forgot-password', ['email' => "user{$i}@example.com"]);
        }

        $this->post('/forgot-password', ['email' => 'again@example.com'])->assertStatus(429);
    }

    public function test_password_reset_does_not_leak_secrets_to_the_activity_log(): void
    {
        Notification::fake();
        $user = User::factory()->empleado()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Brand-New-Passw0rd',
                'password_confirmation' => 'Brand-New-Passw0rd',
            ]);

            return true;
        });

        foreach (Activity::query()->get() as $activity) {
            $this->assertStringNotContainsString('Brand-New-Passw0rd', $activity->properties->toJson());
        }
    }
}
