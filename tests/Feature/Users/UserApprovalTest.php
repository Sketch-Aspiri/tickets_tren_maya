<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class UserApprovalTest extends DatabaseTestCase
{
    private User $jefe;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jefe = User::factory()->jefe()->create();
        $this->team = Team::factory()->create();
    }

    public function test_jefe_approves_a_pending_user_with_role_and_team(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'empleado', 'team_id' => $this->team->id])
            ->assertRedirect(route('users.show', $pending))
            ->assertSessionHas('status', 'user-approved');

        $fresh = $pending->fresh();
        $this->assertSame(UserStatus::Active, $fresh->status);
        $this->assertSame(UserRole::Empleado, $fresh->roleEnum());
        $this->assertSame($this->team->id, $fresh->team_id);
        $this->assertTrue($fresh->canAccessApplication());
    }

    public function test_approval_is_audited_with_causer_and_old_and_new_values(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'coordinador', 'team_id' => $this->team->id]);

        $activity = Activity::query()->where('event', 'approved')->firstOrFail();
        $this->assertSame($this->jefe->id, $activity->causer_id);
        $this->assertSame($pending->id, $activity->subject_id);
        $this->assertSame('pending', $activity->attribute_changes['old']['status']);
        $this->assertNull($activity->attribute_changes['old']['role']);
        $this->assertSame('active', $activity->attribute_changes['attributes']['status']);
        $this->assertSame('coordinador', $activity->attribute_changes['attributes']['role']);
        $this->assertSame($this->team->id, $activity->attribute_changes['attributes']['team_id']);
    }

    public function test_approved_user_can_then_enter_the_application(): void
    {
        $pending = User::factory()->pending()->create();
        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'empleado', 'team_id' => $this->team->id]);

        $this->actingAs($pending->fresh())->get('/dashboard')->assertOk();
    }

    public function test_approval_requires_a_role(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['team_id' => $this->team->id])->assertInvalid(['role']);
        $this->assertTrue($pending->fresh()->isPending());
    }

    public function test_approval_requires_a_team_for_empleado_and_coordinador(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'empleado'])->assertInvalid(['team_id']);
        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'coordinador'])->assertInvalid(['team_id']);
        $this->assertTrue($pending->fresh()->isPending());
        $this->assertCount(0, $pending->fresh()->roles);
    }

    public function test_jefe_de_zona_role_does_not_require_a_team(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'jefe_zona'])->assertSessionHasNoErrors();

        $fresh = $pending->fresh();
        $this->assertSame(UserRole::JefeZona, $fresh->roleEnum());
        $this->assertNull($fresh->team_id);
    }

    public function test_approval_rejects_unknown_role_and_team(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'super_admin', 'team_id' => $this->team->id])->assertInvalid(['role']);
        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'empleado', 'team_id' => 99999])->assertInvalid(['team_id']);
        $this->assertTrue($pending->fresh()->isPending());
    }

    public function test_approval_ignores_extra_fields_that_are_not_role_or_team(): void
    {
        $pending = User::factory()->pending()->create(['email' => 'keep@example.com']);
        $oldHash = $pending->password;

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", [
            'role' => 'empleado',
            'team_id' => $this->team->id,
            'email' => 'changed@example.com',
            'password' => 'Injected-Passw0rd',
            'name' => 'Injected',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $fresh = $pending->fresh();
        $this->assertSame('keep@example.com', $fresh->email);
        $this->assertSame($oldHash, $fresh->password);
        $this->assertNull($fresh->two_factor_secret);
    }

    public function test_an_already_active_user_cannot_be_approved_again(): void
    {
        $active = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->from('/users')->post("/users/{$active->id}/approve", ['role' => 'jefe_zona'])
            ->assertRedirect('/users')
            ->assertSessionHas('error', __('users.errors.already_active'));

        $this->assertSame(UserRole::Empleado, $active->fresh()->roleEnum());
    }

    public function test_jefe_rejects_a_pending_user(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/reject", ['reason' => 'No pertenece a la zona'])
            ->assertRedirect(route('users.index'));

        $fresh = $pending->fresh();
        $this->assertSame(UserStatus::Inactive, $fresh->status);
        $this->assertCount(0, $fresh->roles);
        $this->actingAs($fresh)->get('/dashboard')->assertRedirect(route('account.status'));

        $activity = Activity::query()->where('event', 'rejected')->firstOrFail();
        $this->assertSame($this->jefe->id, $activity->causer_id);
        $this->assertSame('No pertenece a la zona', $activity->properties['reason']);
        $this->assertSame('pending', $activity->attribute_changes['old']['status']);
        $this->assertSame('inactive', $activity->attribute_changes['attributes']['status']);
    }

    public function test_only_pending_accounts_can_be_rejected(): void
    {
        $active = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->from('/users')->post("/users/{$active->id}/reject")
            ->assertSessionHas('error', __('users.errors.not_pending'));

        $this->assertTrue($active->fresh()->isActive());
    }

    public function test_reject_validates_reason_length(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/reject", ['reason' => str_repeat('x', 501)])->assertInvalid(['reason']);
    }

    public function test_a_rejected_account_can_be_approved_later(): void
    {
        $rejected = User::factory()->inactive()->create();

        $this->signIn($this->jefe)->post("/users/{$rejected->id}/approve", ['role' => 'empleado', 'team_id' => $this->team->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($rejected->fresh()->isActive());
    }

    public function test_approval_form_is_shown_for_pending_users_only(): void
    {
        $pending = User::factory()->pending()->create();
        $active = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->get("/users/{$pending->id}")->assertSee(route('users.approve', $pending))->assertSee(route('users.reject', $pending));
        $this->signIn($this->jefe)->get("/users/{$active->id}")->assertDontSee(route('users.approve', $active));
    }
}
