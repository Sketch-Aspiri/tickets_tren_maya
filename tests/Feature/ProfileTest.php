<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class ProfileTest extends DatabaseTestCase
{
    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->get('/profile')->assertOk()->assertSee($user->email);
    }

    public function test_user_can_update_name_and_change_is_logged(): void
    {
        $user = User::factory()->empleado()->create(['name' => 'Nombre Viejo']);

        $this->signIn($user)->patch('/profile', ['name' => 'Nombre Nuevo'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Nombre Nuevo', $user->fresh()->name);
        $activity = Activity::query()->where('subject_id', $user->id)->where('event', 'updated')->latest('id')->first();
        $this->assertSame('Nombre Viejo', $activity->attribute_changes['old']['name']);
        $this->assertSame('Nombre Nuevo', $activity->attribute_changes['attributes']['name']);
    }

    public function test_profile_update_cannot_change_email_status_or_team(): void
    {
        $user = User::factory()->empleado()->create(['email' => 'original@example.com']);
        $teamId = $user->team_id;

        $this->signIn($user)->patch('/profile', [
            'name' => 'Otro Nombre',
            'email' => 'hacker@example.com',
            'status' => 'inactive',
            'team_id' => 999,
        ])->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('original@example.com', $fresh->email);
        $this->assertSame('active', $fresh->status->value);
        $this->assertSame($teamId, $fresh->team_id);
    }

    public function test_profile_update_validates_name(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->patch('/profile', ['name' => ''])->assertInvalid(['name']);
        $this->signIn($user)->patch('/profile', ['name' => str_repeat('a', 256)])->assertInvalid(['name']);
    }

    public function test_user_can_change_password_with_current_password_and_it_is_audited_without_secrets(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->put('/password', [
            'current_password' => UserFactory::DEFAULT_PASSWORD,
            'password' => 'Another-Passw0rd',
            'password_confirmation' => 'Another-Passw0rd',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Another-Passw0rd', $user->fresh()->password));
        $activity = Activity::query()->where('event', 'password_changed')->first();
        $this->assertNotNull($activity);
        $this->assertStringNotContainsString('Another-Passw0rd', $activity->properties->toJson());
    }

    public function test_password_change_requires_correct_current_password_and_strong_new_one(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'Another-Passw0rd',
            'password_confirmation' => 'Another-Passw0rd',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->signIn($user)->put('/password', [
            'current_password' => UserFactory::DEFAULT_PASSWORD,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check(UserFactory::DEFAULT_PASSWORD, $user->fresh()->password));
    }

    public function test_pending_user_cannot_use_profile_endpoints(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->patch('/profile', ['name' => 'X Y'])->assertRedirect(route('account.status'));
        $this->actingAs($user)->put('/password', [])->assertRedirect(route('account.status'));
    }

    public function test_account_deletion_route_does_not_exist(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->delete('/profile', ['password' => UserFactory::DEFAULT_PASSWORD])->assertStatus(405);
        $this->assertNotNull($user->fresh());
    }
}
