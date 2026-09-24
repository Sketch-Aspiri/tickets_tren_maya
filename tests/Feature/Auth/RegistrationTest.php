<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\NewUserPendingApproval;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class RegistrationTest extends DatabaseTestCase
{
    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana María Pérez',
            'email' => 'ana@example.com',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ], $overrides);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_guest_registers_and_account_is_pending_without_role_or_team_or_session(): void
    {
        $response = $this->post('/register', $this->validPayload());

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertNull($user->team_id);
        $this->assertCount(0, $user->roles);
        $this->assertFalse($user->canAccessApplication());
        $this->assertNotSame('Str0ng-Passw0rd!', $user->password);
    }

    public function test_registration_ignores_mass_assignment_of_status_role_and_team(): void
    {
        $this->post('/register', $this->validPayload([
            'status' => 'active',
            'team_id' => 1,
            'role' => 'jefe_zona',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'id' => 999,
        ]))->assertRedirect(route('login'));

        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertNull($user->team_id);
        $this->assertNull($user->two_factor_secret);
        $this->assertCount(0, $user->roles);
        $this->assertNotSame(999, $user->id);
    }

    public function test_registration_notifies_active_jefes_by_database_and_mail_only(): void
    {
        Notification::fake();
        $jefe = User::factory()->jefe()->create();
        $inactiveJefe = User::factory()->jefe()->inactive()->create();
        $empleado = User::factory()->empleado()->create();

        $this->post('/register', $this->validPayload())->assertRedirect(route('login'));

        Notification::assertSentTo($jefe, NewUserPendingApproval::class, function (NewUserPendingApproval $notification) use ($jefe): bool {
            return $notification->via($jefe) === ['database', 'mail']
                && $notification->pendingUserEmail === 'ana@example.com';
        });
        Notification::assertNotSentTo($inactiveJefe, NewUserPendingApproval::class);
        Notification::assertNotSentTo($empleado, NewUserPendingApproval::class);
    }

    public function test_pending_notification_is_stored_in_database_channel_and_mail_is_queued_class(): void
    {
        $jefe = User::factory()->jefe()->create();

        $this->post('/register', $this->validPayload())->assertRedirect(route('login'));

        $this->assertSame(1, $jefe->notifications()->count());
        $data = $jefe->notifications()->first()->data;
        $this->assertSame('user_pending_approval', $data['type']);
        $this->assertSame('ana@example.com', $data['email']);
        $this->assertContains(ShouldQueue::class, class_implements(NewUserPendingApproval::class));
    }

    public function test_registration_is_recorded_in_the_activity_log(): void
    {
        $this->post('/register', $this->validPayload());

        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $activity = Activity::query()->where('subject_id', $user->id)->where('event', 'created')->first();

        $this->assertNotNull($activity);
        $this->assertSame('pending', $activity->attribute_changes['attributes']['status']);
        $this->assertStringNotContainsString('password', $activity->properties->toJson());
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', $this->validPayload(['name' => '']))->assertInvalid(['name']);
        $this->post('/register', $this->validPayload(['name' => 'Evil [link](http://x.test)']))->assertInvalid(['name']);
        $this->post('/register', $this->validPayload(['email' => 'not-an-email']))->assertInvalid(['email']);
        $this->post('/register', $this->validPayload(['email' => 'taken@example.com']))->assertInvalid(['email']);
        $this->post('/register', $this->validPayload(['password_confirmation' => 'different']))->assertInvalid(['password']);
        $this->post('/register', $this->validPayload(['password' => 'short1A', 'password_confirmation' => 'short1A']))->assertInvalid(['password']);
        $this->post('/register', $this->validPayload(['password' => 'alllowercase123', 'password_confirmation' => 'alllowercase123']))->assertInvalid(['password']);
        $this->post('/register', $this->validPayload(['password' => 'NoNumbersHereAtAll', 'password_confirmation' => 'NoNumbersHereAtAll']))->assertInvalid(['password']);
    }

    public function test_email_is_normalized_and_duplicates_differing_in_case_are_rejected(): void
    {
        $this->post('/register', $this->validPayload(['email' => '  ANA@Example.com ']))->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
        $this->post('/register', $this->validPayload(['email' => 'ANA@example.com']))->assertInvalid(['email']);
    }

    public function test_registration_is_rate_limited(): void
    {
        $limit = (int) config('tickets.rate_limits.register_per_hour');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/register', $this->validPayload(['email' => "user{$i}@example.com"]));
        }

        $this->post('/register', $this->validPayload(['email' => 'one-too-many@example.com']))->assertStatus(429);
    }

    public function test_authenticated_users_cannot_open_the_registration_form(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->get('/register')->assertRedirect(route('dashboard'));
    }
}
