<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class AccountStatusTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedUrls(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'profile' => ['/profile'],
            'users' => ['/users'],
            'teams' => ['/teams'],
            'two-factor setup' => ['/two-factor/setup'],
            'two-factor challenge' => ['/two-factor/challenge'],
        ];
    }

    #[DataProvider('protectedUrls')]
    public function test_pending_user_is_redirected_to_status_screen_everywhere(string $url): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->get($url)->assertRedirect(route('account.status'));
    }

    #[DataProvider('protectedUrls')]
    public function test_inactive_user_is_redirected_to_status_screen_everywhere(string $url): void
    {
        $user = User::factory()->empleado()->inactive()->create();

        $this->actingAs($user)->get($url)->assertRedirect(route('account.status'));
    }

    public function test_pending_user_sees_pending_screen_and_can_logout(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->get('/account/status')
            ->assertOk()
            ->assertSee(__('account.status.pending.heading'));

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_inactive_user_sees_inactive_screen(): void
    {
        $user = User::factory()->empleado()->inactive()->create();

        $this->actingAs($user)->get('/account/status')->assertOk()->assertSee(__('account.status.inactive.heading'));
    }

    public function test_active_user_without_role_is_treated_as_without_access(): void
    {
        $user = User::factory()->active()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('account.status'));
        $this->actingAs($user)->get('/account/status')->assertOk()->assertSee(__('account.status.no_role.heading'));
    }

    public function test_active_user_is_bounced_from_status_screen_to_dashboard(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->get('/account/status')->assertRedirect(route('dashboard'));
    }

    public function test_pending_user_gets_403_json_instead_of_a_redirect(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->getJson('/dashboard')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'account_not_active');
    }

    public function test_deactivating_a_user_cuts_off_an_existing_session_immediately(): void
    {
        $user = User::factory()->empleado()->create();
        $this->signIn($user)->get('/dashboard')->assertOk();

        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->signIn($user->fresh())->get('/dashboard')->assertRedirect(route('account.status'));
    }
}
