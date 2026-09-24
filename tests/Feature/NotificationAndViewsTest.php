<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\NewUserPendingApproval;
use Database\Factories\UserFactory;
use Tests\DatabaseTestCase;

class NotificationAndViewsTest extends DatabaseTestCase
{
    public function test_pending_registration_mail_is_in_spanish_links_to_the_user_and_escapes_content(): void
    {
        $jefe = User::factory()->jefe()->create(['name' => 'Jefa Uno']);
        $pending = User::factory()->pending()->create();
        $notification = new NewUserPendingApproval($pending->id, 'Nombre <b>Raro</b>', 'raro@example.com');

        $mail = $notification->toMail($jefe);
        $html = (string) $mail->render();

        $this->assertSame(__('notifications.new_user_pending.subject'), $mail->subject);
        $this->assertStringContainsString(route('users.show', $pending), $html);
        $this->assertStringContainsString('Nueva cuenta pendiente'.'', __('notifications.new_user_pending.subject'));
        $this->assertStringNotContainsString('<b>Raro</b>', $html);
    }

    public function test_recovery_codes_are_shown_once_from_the_flash_on_the_profile_page(): void
    {
        $user = User::factory()->coordinador()->create();

        // Los codigos en claro se ven en la respuesta que sigue a la generacion y en ninguna posterior.
        $this->signIn($user)
            ->followingRedirects()
            ->post('/two-factor/recovery-codes', ['password' => UserFactory::DEFAULT_PASSWORD])
            ->assertOk()
            ->assertSee(__('two_factor.settings.recovery_warning'));

        $this->signIn($user)->get('/profile')->assertOk()->assertDontSee(__('two_factor.settings.recovery_warning'));
    }

    public function test_profile_of_a_required_role_does_not_offer_disabling_two_factor(): void
    {
        $coordinador = User::factory()->coordinador()->create();
        $empleado = User::factory()->empleado()->withTwoFactor()->create();

        $this->signIn($coordinador)->get('/profile')->assertOk()->assertDontSee(__('two_factor.settings.disable'));
        $this->signIn($empleado)->get('/profile')->assertOk()->assertSee(__('two_factor.settings.disable'));
    }

    public function test_dashboard_shows_pending_count_only_to_the_jefe(): void
    {
        $jefe = User::factory()->jefe()->create();
        $empleado = User::factory()->empleado()->create();
        User::factory()->count(3)->pending()->create();

        $this->signIn($jefe)->get('/dashboard')->assertOk()->assertSee(__('common.dashboard.pending_registrations'));
        $this->signIn($empleado)->get('/dashboard')->assertOk()->assertDontSee(__('common.dashboard.pending_registrations'));
    }

    public function test_dates_are_displayed_in_the_local_timezone(): void
    {
        $jefe = User::factory()->jefe()->create();
        $target = User::factory()->pending()->create(['created_at' => '2026-01-15 03:30:00']);

        // 03:30 UTC == 22:30 del dia anterior en Quintana Roo (UTC-5).
        $this->signIn($jefe)->get("/users/{$target->id}")->assertSee('14/01/2026 22:30');
    }

    public function test_pages_are_rendered_in_spanish(): void
    {
        $this->assertSame('es', app()->getLocale());
        $this->get('/login')->assertSee(__('auth.login.submit'))->assertSee('lang="es"', false);
        $this->assertSame('America/Cancun', config('app.display_timezone'));
    }
}
