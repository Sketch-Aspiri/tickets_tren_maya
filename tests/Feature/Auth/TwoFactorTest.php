<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TwoFactorService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class TwoFactorTest extends DatabaseTestCase
{
    private const VERIFY = '/two-factor/challenge';

    private const SETUP = '/two-factor/setup';

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesRequiringTwoFactor(): array
    {
        return ['jefe' => ['jefe'], 'coordinador' => ['coordinador']];
    }

    // --- Obligatorio por rol -------------------------------------------------

    #[DataProvider('rolesRequiringTwoFactor')]
    public function test_roles_that_require_two_factor_are_forced_to_set_it_up(string $state): void
    {
        $user = User::factory()->{$state}()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        foreach (['/dashboard', '/profile', '/users', '/teams'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('two-factor.setup'));
        }
    }

    #[DataProvider('rolesRequiringTwoFactor')]
    public function test_roles_that_require_two_factor_face_the_challenge_on_every_new_session(string $state): void
    {
        $user = User::factory()->{$state}()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_empleado_is_not_forced_to_use_two_factor(): void
    {
        $user = User::factory()->empleado()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_empleado_with_two_factor_enabled_still_faces_the_challenge(): void
    {
        $user = User::factory()->empleado()->withTwoFactor()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_incomplete_two_factor_gets_json_403_envelope(): void
    {
        $user = User::factory()->coordinador()->create();

        $this->actingAs($user)->getJson('/dashboard')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'two_factor_challenge_required');
    }

    public function test_user_cannot_bypass_challenge_with_a_verification_flag_of_another_user(): void
    {
        $user = User::factory()->jefe()->create();
        $other = User::factory()->jefe()->create();

        $this->actingAs($user)->withSession([TwoFactorService::SESSION_KEY => $other->id])
            ->get('/dashboard')->assertRedirect(route('two-factor.challenge'));
    }

    // --- Configuracion ---------------------------------------------------------

    public function test_setup_screen_shows_qr_and_manual_key_and_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->coordinador()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $response = $this->actingAs($user)->get(self::SETUP)->assertOk()->assertSee('<svg', false);

        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotNull($secret);
        $response->assertSee($secret);

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);
    }

    public function test_setup_reuses_the_pending_secret_until_confirmed(): void
    {
        $user = User::factory()->coordinador()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $this->actingAs($user)->get(self::SETUP);
        $first = $user->fresh()->two_factor_secret;
        $this->actingAs($user)->get(self::SETUP);

        $this->assertSame($first, $user->fresh()->two_factor_secret);
    }

    public function test_confirming_with_a_valid_code_enables_two_factor_and_returns_hashed_recovery_codes(): void
    {
        $user = User::factory()->coordinador()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->actingAs($user)->get(self::SETUP);
        $user = $user->fresh();

        $response = $this->actingAs($user)->post(self::SETUP, ['code' => $this->validOtp($user)]);

        $response->assertRedirect(route('profile.edit'))->assertSessionHas('recovery_codes');
        $plainCodes = session('recovery_codes');
        $this->assertCount((int) config('tickets.two_factor.recovery_codes'), $plainCodes);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasTwoFactorEnabled());
        $this->assertSame($user->id, session(TwoFactorService::SESSION_KEY));

        $hashes = $fresh->two_factor_recovery_codes;
        $this->assertCount(count($plainCodes), $hashes);
        foreach ($plainCodes as $plain) {
            $this->assertNotContains($plain, $hashes);
        }
        $this->assertTrue(Hash::check(str_replace('-', '', $plainCodes[0]), $hashes[0]));
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_enabled', 'causer_id' => $user->id]);
    }

    public function test_confirming_with_a_wrong_code_fails_and_does_not_enable_two_factor(): void
    {
        $user = User::factory()->coordinador()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->actingAs($user)->get(self::SETUP);

        $this->actingAs($user)->post(self::SETUP, ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_validates_code_format(): void
    {
        $user = User::factory()->coordinador()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $this->actingAs($user)->post(self::SETUP, [])->assertInvalid(['code']);
        $this->actingAs($user)->post(self::SETUP, ['code' => 'abcdef'])->assertInvalid(['code']);
        $this->actingAs($user)->post(self::SETUP, ['code' => '12345'])->assertInvalid(['code']);
    }

    public function test_setup_screen_redirects_to_profile_when_already_enabled(): void
    {
        $user = User::factory()->empleado()->withTwoFactor()->create();

        $this->signIn($user)->get(self::SETUP)->assertRedirect(route('profile.edit'));
    }

    // --- Desafio -----------------------------------------------------------------

    public function test_correct_code_passes_the_challenge_and_grants_access(): void
    {
        $user = User::factory()->jefe()->create();

        $this->actingAs($user)->post(self::VERIFY, ['code' => $this->validOtp($user)])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame($user->id, session(TwoFactorService::SESSION_KEY));
        $this->actingAs($user)->withSession([TwoFactorService::SESSION_KEY => $user->id])->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_challenge_passed', 'causer_id' => $user->id]);
    }

    public function test_wrong_code_fails_and_is_logged(): void
    {
        $user = User::factory()->jefe()->create();

        $this->actingAs($user)->post(self::VERIFY, ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertNull(session(TwoFactorService::SESSION_KEY));
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_challenge_failed', 'causer_id' => $user->id]);
    }

    public function test_a_totp_code_cannot_be_reused(): void
    {
        $user = User::factory()->jefe()->create();
        $code = $this->validOtp($user);

        $this->actingAs($user)->post(self::VERIFY, ['code' => $code])->assertSessionHasNoErrors();

        // Nueva sesion (login otra vez) con el mismo codigo aun vigente.
        $this->flushSession();
        $this->actingAs($user->fresh())->post(self::VERIFY, ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull(session(TwoFactorService::SESSION_KEY));
    }

    public function test_code_with_spaces_is_accepted(): void
    {
        $user = User::factory()->jefe()->create();
        $code = $this->validOtp($user);

        $this->actingAs($user)->post(self::VERIFY, ['code' => substr($code, 0, 3).' '.substr($code, 3)])
            ->assertSessionHasNoErrors();
    }

    public function test_challenge_requires_a_code_or_a_recovery_code(): void
    {
        $user = User::factory()->jefe()->create();

        $this->actingAs($user)->post(self::VERIFY, [])->assertInvalid(['code', 'recovery_code']);
    }

    public function test_recovery_code_works_exactly_once(): void
    {
        $user = User::factory()->jefe()->create();
        $codes = ['abcde-fghij', 'klmno-pqrst'];
        $user->forceFill(['two_factor_recovery_codes' => array_map(
            fn (string $code): string => Hash::make(str_replace('-', '', $code)),
            $codes,
        )])->save();

        $this->actingAs($user)->post(self::VERIFY, ['recovery_code' => 'ABCDE-FGHIJ'])->assertSessionHasNoErrors();
        $this->assertSame($user->id, session(TwoFactorService::SESSION_KEY));
        $this->assertCount(1, $user->fresh()->two_factor_recovery_codes);

        $this->flushSession();
        $this->actingAs($user->fresh())->post(self::VERIFY, ['recovery_code' => 'abcde-fghij'])
            ->assertSessionHasErrors('recovery_code');
        $this->assertNull(session(TwoFactorService::SESSION_KEY));
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_recovery_used', 'causer_id' => $user->id]);
    }

    public function test_wrong_recovery_code_is_rejected(): void
    {
        $user = User::factory()->jefe()->create();

        $this->actingAs($user)->post(self::VERIFY, ['recovery_code' => 'nope-nope1'])->assertSessionHasErrors('recovery_code');
    }

    public function test_challenge_is_rate_limited_with_429(): void
    {
        $user = User::factory()->jefe()->create();
        $limit = (int) config('tickets.rate_limits.two_factor_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($user)->post(self::VERIFY, ['code' => '000000']);
        }

        $this->actingAs($user)->post(self::VERIFY, ['code' => $this->validOtp($user)])->assertStatus(429);
        $this->assertNull(session(TwoFactorService::SESSION_KEY));
    }

    public function test_challenge_screen_redirects_to_dashboard_when_not_needed(): void
    {
        $empleado = User::factory()->empleado()->create();

        $this->signIn($empleado)->get(self::VERIFY)->assertRedirect(route('dashboard'));
    }

    public function test_pending_user_cannot_reach_two_factor_endpoints(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->post(self::VERIFY, ['code' => '123456'])->assertRedirect(route('account.status'));
        $this->actingAs($user)->post(self::SETUP, ['code' => '123456'])->assertRedirect(route('account.status'));
    }

    public function test_guest_cannot_reach_two_factor_endpoints(): void
    {
        $this->post(self::VERIFY, ['code' => '123456'])->assertRedirect('/login');
        $this->get(self::SETUP)->assertRedirect('/login');
    }

    // --- Desactivar / regenerar ------------------------------------------------

    public function test_empleado_can_disable_two_factor_with_password(): void
    {
        $user = User::factory()->empleado()->withTwoFactor()->create();

        $this->signIn($user)->delete('/two-factor', ['password' => UserFactory::DEFAULT_PASSWORD])
            ->assertRedirect(route('profile.edit'));

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_disabled', 'causer_id' => $user->id]);
    }

    public function test_disabling_requires_the_correct_password(): void
    {
        $user = User::factory()->empleado()->withTwoFactor()->create();

        $this->signIn($user)->delete('/two-factor', ['password' => 'incorrect'])
            ->assertSessionHasErrorsIn('twoFactor', 'password');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    #[DataProvider('rolesRequiringTwoFactor')]
    public function test_roles_that_require_two_factor_cannot_disable_it(string $state): void
    {
        $user = User::factory()->{$state}()->create();

        $this->signIn($user)->delete('/two-factor', ['password' => UserFactory::DEFAULT_PASSWORD])->assertForbidden();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_recovery_codes_can_be_regenerated_with_password_and_old_ones_stop_working(): void
    {
        $user = User::factory()->empleado()->withTwoFactor()->create();
        $user->forceFill(['two_factor_recovery_codes' => [Hash::make('oldcodeabc')]])->save();

        $response = $this->signIn($user)->post('/two-factor/recovery-codes', ['password' => UserFactory::DEFAULT_PASSWORD]);

        $response->assertRedirect(route('profile.edit'))->assertSessionHas('recovery_codes');
        $hashes = $user->fresh()->two_factor_recovery_codes;
        $this->assertCount((int) config('tickets.two_factor.recovery_codes'), $hashes);
        foreach ($hashes as $hash) {
            $this->assertFalse(Hash::check('oldcodeabc', $hash));
        }
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_recovery_regenerated']);
    }

    public function test_regenerating_codes_requires_password(): void
    {
        $user = User::factory()->empleado()->withTwoFactor()->create();

        $this->signIn($user)->post('/two-factor/recovery-codes', ['password' => 'incorrect'])
            ->assertSessionHasErrorsIn('twoFactor', 'password');
    }

    // --- Secretos nunca expuestos ----------------------------------------------

    public function test_secrets_are_hidden_from_serialization_and_never_reach_the_activity_log(): void
    {
        $user = User::factory()->coordinador()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->actingAs($user)->get(self::SETUP);
        $user = $user->fresh();
        $secret = $user->two_factor_secret;
        $this->actingAs($user)->post(self::SETUP, ['code' => $this->validOtp($user)]);

        $array = $user->fresh()->toArray();
        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_timestamp'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $array);
        }

        foreach (Activity::query()->get() as $activity) {
            $blob = $activity->properties->toJson().($activity->attribute_changes?->toJson() ?? '');
            $this->assertStringNotContainsString($secret, $blob);
            $this->assertStringNotContainsString('two_factor_secret', $blob);
            $this->assertStringNotContainsString('two_factor_recovery_codes', $blob);
        }
    }
}
