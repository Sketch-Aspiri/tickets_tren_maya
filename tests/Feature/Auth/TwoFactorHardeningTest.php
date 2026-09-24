<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\UsesDatabaseSessions;
use Tests\DatabaseTestCase;

class TwoFactorHardeningTest extends DatabaseTestCase
{
    use UsesDatabaseSessions;

    protected function tearDown(): void
    {
        Activity::flushEventListeners();

        parent::tearDown();
    }

    private function makeAuditFail(): void
    {
        Activity::creating(function (): void {
            throw new RuntimeException('audit down');
        });
    }

    private function service(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    // --- M5: estado + auditoria atomicos -----------------------------------------------

    public function test_a_passed_challenge_is_rolled_back_when_the_audit_fails(): void
    {
        $user = User::factory()->coordinador()->create();
        $code = $this->validOtp($user);
        $this->makeAuditFail();

        try {
            $this->service()->passesChallenge($user, $code);
            $this->fail('Se esperaba la excepcion de auditoria.');
        } catch (RuntimeException) {
            $this->assertNull($user->fresh()->two_factor_last_timestamp);
        }
    }

    public function test_a_recovery_code_is_not_consumed_when_the_audit_fails(): void
    {
        $user = User::factory()->coordinador()->create();
        $user->forceFill(['two_factor_recovery_codes' => [Hash::make('aaaaabbbbb'), Hash::make('cccccddddd')]])->save();
        $this->makeAuditFail();

        try {
            $this->service()->passesRecoveryChallenge($user, 'aaaaa-bbbbb');
            $this->fail('Se esperaba la excepcion de auditoria.');
        } catch (RuntimeException) {
            $this->assertCount(2, $user->fresh()->two_factor_recovery_codes);
        }
    }

    public function test_setup_confirmation_is_rolled_back_when_the_audit_fails(): void
    {
        $user = User::factory()->coordinador()->create(['two_factor_confirmed_at' => null]);
        $code = $this->validOtp($user);
        $this->makeAuditFail();

        try {
            $this->service()->confirmSetup($user, $code);
            $this->fail('Se esperaba la excepcion de auditoria.');
        } catch (RuntimeException) {
            $this->assertNull($user->fresh()->two_factor_confirmed_at);
        }
    }

    public function test_regenerating_and_disabling_are_rolled_back_when_the_audit_fails(): void
    {
        $empleado = User::factory()->empleado()->withTwoFactor()->create();
        $empleado->forceFill(['two_factor_recovery_codes' => [Hash::make('aaaaabbbbb')]])->save();
        $this->makeAuditFail();

        try {
            $this->service()->regenerateRecoveryCodes($empleado);
            $this->fail('Se esperaba la excepcion de auditoria.');
        } catch (RuntimeException) {
            $this->assertCount(1, $empleado->fresh()->two_factor_recovery_codes);
        }

        try {
            $this->service()->disable($empleado);
            $this->fail('Se esperaba la excepcion de auditoria.');
        } catch (RuntimeException) {
            $this->assertTrue($empleado->fresh()->hasTwoFactorEnabled());
        }
    }

    // --- TOCTOU: consumo bajo bloqueo ----------------------------------------------------

    public function test_the_challenge_reads_the_timestamp_from_the_database_not_from_a_stale_instance(): void
    {
        $user = User::factory()->coordinador()->create();
        $stale = User::query()->findOrFail($user->id);

        $this->assertTrue($this->service()->passesChallenge($user, $this->validOtp($user)));

        // Segunda peticion concurrente con una copia obsoleta del usuario: el codigo ya se uso.
        $this->assertFalse($this->service()->passesChallenge($stale, $this->validOtp($stale)));
    }

    public function test_a_recovery_code_cannot_be_consumed_twice_from_stale_copies(): void
    {
        $user = User::factory()->coordinador()->create();
        $user->forceFill(['two_factor_recovery_codes' => [Hash::make('aaaaabbbbb')]])->save();
        $stale = User::query()->findOrFail($user->id);

        $this->assertTrue($this->service()->passesRecoveryChallenge($user, 'aaaaa-bbbbb'));
        $this->assertFalse($this->service()->passesRecoveryChallenge($stale, 'aaaaa-bbbbb'));
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    // --- Cache-Control: no-store en pantallas con secretos ---------------------------------

    public function test_pages_that_show_totp_secrets_and_recovery_codes_are_not_cacheable(): void
    {
        $coordinador = User::factory()->coordinador()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $empleado = User::factory()->empleado()->create();

        $setup = $this->actingAs($coordinador)->get('/two-factor/setup')->assertOk();
        $this->assertStringContainsString('no-store', (string) $setup->headers->get('Cache-Control'));

        $profile = $this->signIn($empleado)->get('/profile')->assertOk();
        $this->assertStringContainsString('no-store', (string) $profile->headers->get('Cache-Control'));
    }

    public function test_the_challenge_page_is_not_cacheable_either(): void
    {
        $coordinador = User::factory()->coordinador()->create();

        $challenge = $this->actingAs($coordinador)->get('/two-factor/challenge')->assertOk();
        $this->assertStringContainsString('no-store', (string) $challenge->headers->get('Cache-Control'));
    }

    // --- users:reset-2fa ------------------------------------------------------------------------

    public function test_reset_command_clears_all_two_factor_data_and_audits_without_secrets(): void
    {
        $user = User::factory()->coordinador()->create(['email' => 'coord@example.com']);
        $user->forceFill(['two_factor_recovery_codes' => [Hash::make('aaaaabbbbb')], 'two_factor_last_timestamp' => 123])->save();
        $secret = $user->two_factor_secret;

        $this->artisan('users:reset-2fa', ['email' => 'Coord@Example.com'])
            ->expectsConfirmation(__('two_factor.command.confirm', ['email' => 'coord@example.com']), 'yes')
            ->expectsOutputToContain(__('two_factor.command.done', ['email' => 'coord@example.com']))
            ->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertNull($fresh->two_factor_last_timestamp);
        $this->assertFalse($fresh->hasTwoFactorEnabled());

        $activity = Activity::query()->where('event', 'two_factor_reset_by_console')->firstOrFail();
        $this->assertSame($user->id, $activity->subject_id);
        $this->assertNull($activity->causer_id);
        $this->assertStringNotContainsString($secret, $activity->properties->toJson().json_encode($activity->attribute_changes));
    }

    public function test_reset_command_does_nothing_when_the_confirmation_is_declined(): void
    {
        $user = User::factory()->coordinador()->create();

        $this->artisan('users:reset-2fa', ['email' => $user->email])
            ->expectsConfirmation(__('two_factor.command.confirm', ['email' => $user->email]), 'no')
            ->assertFailed();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseMissing('activity_log', ['event' => 'two_factor_reset_by_console']);
    }

    public function test_reset_command_fails_for_unknown_users(): void
    {
        $this->artisan('users:reset-2fa', ['email' => 'ghost@example.com'])
            ->expectsOutputToContain(__('two_factor.command.not_found', ['email' => 'ghost@example.com']))
            ->assertFailed();
    }

    public function test_reset_command_reports_when_there_is_nothing_to_reset(): void
    {
        $user = User::factory()->empleado()->create();

        $this->artisan('users:reset-2fa', ['email' => $user->email])
            ->expectsConfirmation(__('two_factor.command.confirm', ['email' => $user->email]), 'yes')
            ->expectsOutputToContain(__('two_factor.command.nothing', ['email' => $user->email]))
            ->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['event' => 'two_factor_reset_by_console']);
    }

    public function test_after_a_reset_the_user_must_enroll_again_and_old_sessions_are_gone(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->coordinador()->create();
        $cookie = $this->loginViaHttp($user);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->artisan('users:reset-2fa', ['email' => $user->email])
            ->expectsConfirmation(__('two_factor.command.confirm', ['email' => $user->email]), 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->asSession($cookie)->get('/dashboard')->assertRedirect(route('login'));

        $newCookie = $this->loginViaHttp($user);
        $this->asSession($newCookie)->get('/dashboard')->assertRedirect(route('two-factor.setup'));
    }
}
