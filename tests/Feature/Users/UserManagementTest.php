<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Team;
use App\Models\User;
use App\Services\UserService;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class UserManagementTest extends DatabaseTestCase
{
    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jefe = User::factory()->jefe()->create();
    }

    // --- Activar / inactivar ---------------------------------------------------

    public function test_jefe_deactivates_an_active_user_and_it_is_audited(): void
    {
        $empleado = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->post("/users/{$empleado->id}/deactivate", ['reason' => 'Baja'])
            ->assertRedirect(route('users.show', $empleado));

        $this->assertSame(UserStatus::Inactive, $empleado->fresh()->status);
        $activity = Activity::query()->where('event', 'deactivated')->firstOrFail();
        $this->assertSame($this->jefe->id, $activity->causer_id);
        $this->assertSame('active', $activity->attribute_changes['old']['status']);
        $this->assertSame('inactive', $activity->attribute_changes['attributes']['status']);
        $this->assertSame('Baja', $activity->properties['reason']);
    }

    public function test_jefe_activates_an_inactive_user_that_already_has_role_and_team(): void
    {
        $empleado = User::factory()->empleado()->inactive()->create();

        $this->signIn($this->jefe)->post("/users/{$empleado->id}/activate")->assertRedirect(route('users.show', $empleado));

        $this->assertTrue($empleado->fresh()->isActive());
        $this->assertDatabaseHas('activity_log', ['event' => 'activated', 'subject_id' => $empleado->id]);
    }

    public function test_activation_is_refused_when_the_user_has_no_role_and_team(): void
    {
        $rejected = User::factory()->inactive()->create();

        $this->signIn($this->jefe)->from('/users')->post("/users/{$rejected->id}/activate")
            ->assertSessionHas('error', __('users.errors.needs_role_and_team'));

        $this->assertTrue($rejected->fresh()->isInactive());
    }

    public function test_pending_user_cannot_be_activated_without_approval(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->from('/users')->post("/users/{$pending->id}/activate")
            ->assertSessionHas('error', __('users.errors.not_inactive'));

        $this->assertTrue($pending->fresh()->isPending());
    }

    // --- Cambiar rol / equipo ----------------------------------------------------

    public function test_jefe_changes_role_and_team_and_the_change_is_audited(): void
    {
        $empleado = User::factory()->empleado()->create();
        $newTeam = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'coordinador', 'team_ids' => [$newTeam->id]])
            ->assertRedirect(route('users.show', $empleado))
            ->assertSessionHas('status', 'user-updated');

        $fresh = $empleado->fresh();
        $this->assertSame(UserRole::Coordinador, $fresh->roleEnum());
        $this->assertSame([$newTeam->id], $fresh->teamIds());

        $activity = Activity::query()->where('event', 'role_changed')->firstOrFail();
        $this->assertSame($this->jefe->id, $activity->causer_id);
        $this->assertSame('empleado', $activity->attribute_changes['old']['role']);
        $this->assertSame('coordinador', $activity->attribute_changes['attributes']['role']);
        $this->assertSame([$newTeam->id], $activity->attribute_changes['attributes']['team_ids']);
    }

    public function test_changing_only_the_team_records_a_team_changed_event(): void
    {
        $empleado = User::factory()->empleado()->create();
        $newTeam = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'empleado', 'team_ids' => [$newTeam->id]]);

        $this->assertSame([$newTeam->id], $empleado->teamIds());
        $this->assertDatabaseHas('activity_log', ['event' => 'team_changed', 'subject_id' => $empleado->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => 'role_changed']);
    }

    public function test_updating_with_no_changes_writes_no_audit_entry(): void
    {
        $empleado = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'empleado', 'team_ids' => $empleado->teamIds()]);

        $this->assertDatabaseMissing('activity_log', ['subject_id' => $empleado->id, 'event' => 'role_changed']);
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $empleado->id, 'event' => 'team_changed']);
    }

    public function test_update_validates_role_and_team(): void
    {
        $empleado = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", [])->assertInvalid(['role']);
        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'empleado'])->assertInvalid(['team_ids']);
        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'x', 'team_ids' => [1]])->assertInvalid(['role']);
    }

    public function test_update_ignores_mass_assignment_of_other_attributes(): void
    {
        $empleado = User::factory()->empleado()->create(['email' => 'stay@example.com']);

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", [
            'role' => 'empleado',
            'team_ids' => $empleado->teamIds(),
            'email' => 'other@example.com',
            'status' => 'inactive',
            'password' => 'Injected-Passw0rd',
        ]);

        $fresh = $empleado->fresh();
        $this->assertSame('stay@example.com', $fresh->email);
        $this->assertTrue($fresh->isActive());
    }

    public function test_only_active_users_can_have_role_and_team_changed_through_update(): void
    {
        $pending = User::factory()->pending()->create();
        $team = Team::factory()->create();

        $this->signIn($this->jefe)->from('/users')->put("/users/{$pending->id}", ['role' => 'empleado', 'team_ids' => [$team->id]])
            ->assertSessionHas('error', __('users.errors.not_active'));

        $this->assertCount(0, $pending->fresh()->roles);
    }

    // --- Integridad ----------------------------------------------------------------

    public function test_jefe_cannot_deactivate_themselves(): void
    {
        User::factory()->jefe()->create();

        $this->signIn($this->jefe)->from('/users')->post("/users/{$this->jefe->id}/deactivate")
            ->assertSessionHas('error', __('users.errors.cannot_deactivate_self'));

        $this->assertTrue($this->jefe->fresh()->isActive());
    }

    public function test_jefe_cannot_remove_their_own_jefe_role(): void
    {
        User::factory()->jefe()->create();
        $team = Team::factory()->create();

        $this->signIn($this->jefe)->from('/users')->put("/users/{$this->jefe->id}", ['role' => 'empleado', 'team_ids' => [$team->id]])
            ->assertSessionHas('error', __('users.errors.cannot_change_own_role'));

        $this->assertSame(UserRole::JefeZona, $this->jefe->fresh()->roleEnum());
    }

    public function test_a_jefe_can_demote_another_jefe_while_at_least_one_remains(): void
    {
        $other = User::factory()->jefe()->create();
        $team = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$other->id}", ['role' => 'empleado', 'team_ids' => [$team->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(UserRole::Empleado, $other->fresh()->roleEnum());
    }

    public function test_service_refuses_to_deactivate_the_last_active_jefe_even_for_a_different_actor(): void
    {
        $actor = User::factory()->coordinador()->create();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage(__('users.errors.last_jefe'));

        try {
            app(UserService::class)->deactivate($actor, $this->jefe);
        } finally {
            $this->assertTrue($this->jefe->fresh()->isActive());
        }
    }

    public function test_service_refuses_to_demote_the_last_active_jefe_even_for_a_different_actor(): void
    {
        $actor = User::factory()->coordinador()->create();
        $team = Team::factory()->create();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage(__('users.errors.last_jefe'));

        try {
            app(UserService::class)->updateRoleAndTeams($actor, $this->jefe, UserRole::Empleado, [$team->id]);
        } finally {
            $this->assertSame(UserRole::JefeZona, $this->jefe->fresh()->roleEnum());
        }
    }

    public function test_an_inactive_jefe_does_not_count_as_a_remaining_jefe(): void
    {
        User::factory()->jefe()->inactive()->create();
        $actor = User::factory()->coordinador()->create();

        $this->expectException(BusinessRuleException::class);

        app(UserService::class)->deactivate($actor, $this->jefe);
    }

    public function test_deactivating_a_coordinator_releases_the_teams_they_coordinate(): void
    {
        $coordinador = User::factory()->coordinador()->create();
        $team = Team::factory()->create(['coordinator_id' => $coordinador->id]);

        $this->signIn($this->jefe)->post("/users/{$coordinador->id}/deactivate");

        $this->assertNull($team->fresh()->coordinator_id);
    }

    public function test_changing_a_coordinators_role_releases_their_teams(): void
    {
        $coordinador = User::factory()->coordinador()->create();
        $team = Team::factory()->create(['coordinator_id' => $coordinador->id]);

        $this->signIn($this->jefe)->put("/users/{$coordinador->id}", ['role' => 'empleado', 'team_ids' => $coordinador->teamIds()]);

        $this->assertNull($team->fresh()->coordinator_id);
    }

    public function test_a_user_without_role_and_team_never_gains_access_through_status_changes_alone(): void
    {
        $pending = User::factory()->pending()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/activate");
        $this->signIn($this->jefe)->post("/users/{$pending->id}/reject");
        $this->signIn($this->jefe)->post("/users/{$pending->id}/activate");

        $this->assertFalse($pending->fresh()->canAccessApplication());
    }

    // --- Varios equipos --------------------------------------------------------------

    public function test_a_user_can_belong_to_several_teams_and_the_change_is_audited(): void
    {
        $empleado = User::factory()->empleado()->create();
        $original = $empleado->teamIds()[0];
        $second = Team::factory()->create();
        $third = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'empleado', 'team_ids' => [$original, $second->id, $third->id]])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$original, $second->id, $third->id], $empleado->teamIds());

        $activity = Activity::query()->where('event', 'team_changed')->firstOrFail();
        $this->assertSame([$original], $activity->attribute_changes['old']['team_ids']);
        $this->assertEqualsCanonicalizing([$original, $second->id, $third->id], $activity->attribute_changes['attributes']['team_ids']);
    }

    public function test_leaving_a_team_removes_only_that_membership(): void
    {
        $empleado = User::factory()->empleado()->create();
        $second = Team::factory()->create();
        $empleado->teams()->attach($second->id);

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'empleado', 'team_ids' => [$second->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$second->id], $empleado->teamIds());
    }

    public function test_empleado_and_coordinador_keep_needing_at_least_one_team(): void
    {
        $empleado = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'empleado', 'team_ids' => []])->assertInvalid(['team_ids']);
        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'coordinador'])->assertInvalid(['team_ids']);
        $this->assertNotEmpty($empleado->teamIds());
    }

    public function test_administrador_and_jefe_may_have_no_team_or_several(): void
    {
        $admin = User::factory()->administrador()->create();
        $team = Team::factory()->create();
        $other = Team::factory()->create();

        $this->signIn($admin)->put("/users/{$this->jefe->id}", ['role' => 'jefe_zona'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->jefe->teamIds());

        $this->signIn($admin)->put("/users/{$this->jefe->id}", ['role' => 'jefe_zona', 'team_ids' => [$team->id, $other->id]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$team->id, $other->id], $this->jefe->teamIds());
    }

    public function test_the_service_rejects_unknown_teams(): void
    {
        $empleado = User::factory()->empleado()->create();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage(__('users.errors.invalid_team'));

        app(UserService::class)->updateRoleAndTeams($this->jefe, $empleado, UserRole::Empleado, [999999]);
    }

    public function test_filtering_users_by_team_finds_members_of_several_teams(): void
    {
        $shared = User::factory()->empleado()->create(['name' => 'Compartido']);
        $first = $shared->teamIds()[0];
        $second = Team::factory()->create();
        $shared->teams()->attach($second->id);
        User::factory()->empleado()->create(['name' => 'Solo Otro']);

        $this->signIn($this->jefe)->get("/users?team_id={$second->id}")->assertOk()->assertSee('Compartido')->assertDontSee('Solo Otro');
        $this->signIn($this->jefe)->get("/users?team_id={$first}")->assertOk()->assertSee('Compartido')->assertDontSee('Solo Otro');
    }

    // --- Rol administrador -------------------------------------------------------------

    public function test_a_jefe_cannot_manage_an_administrador_account(): void
    {
        $admin = User::factory()->administrador()->create();
        $team = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$admin->id}", ['role' => 'empleado', 'team_ids' => [$team->id]])->assertForbidden();
        $this->signIn($this->jefe)->post("/users/{$admin->id}/deactivate")->assertForbidden();

        $this->assertSame(UserRole::Administrador, $admin->fresh()->roleEnum());
        $this->assertTrue($admin->fresh()->isActive());

        $inactive = User::factory()->administrador()->inactive()->create();
        $this->signIn($this->jefe)->post("/users/{$inactive->id}/activate")->assertForbidden();
        $this->assertTrue($inactive->fresh()->isInactive());
    }

    public function test_a_jefe_can_still_view_an_administrador_account_but_not_promote_anyone_to_it(): void
    {
        $admin = User::factory()->administrador()->create(['name' => 'Ada Admin']);
        $empleado = User::factory()->empleado()->create();

        $this->signIn($this->jefe)->get("/users/{$admin->id}")->assertOk()->assertSee('Ada Admin');
        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'administrador'])->assertInvalid(['role']);

        $this->assertSame(UserRole::Empleado, $empleado->fresh()->roleEnum());
    }

    public function test_the_role_form_offers_administrador_only_to_administradores(): void
    {
        $empleado = User::factory()->empleado()->create();
        $admin = User::factory()->administrador()->create();

        $forJefe = $this->signIn($this->jefe)->get("/users/{$empleado->id}")->assertOk()->getContent();
        $forAdmin = $this->signIn($admin)->get("/users/{$empleado->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('value="administrador"', $forJefe);
        $this->assertStringContainsString('value="administrador"', $forAdmin);
    }

    public function test_an_administrador_manages_jefes_empleados_and_other_administradores(): void
    {
        $admin = User::factory()->administrador()->create();
        $otherAdmin = User::factory()->administrador()->create();
        $empleado = User::factory()->empleado()->create();

        $this->signIn($admin)->put("/users/{$empleado->id}", ['role' => 'administrador'])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Administrador, $empleado->fresh()->roleEnum());

        $this->signIn($admin)->post("/users/{$otherAdmin->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertTrue($otherAdmin->fresh()->isInactive());

        User::factory()->jefe()->create();
        $this->signIn($admin)->post("/users/{$this->jefe->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertTrue($this->jefe->fresh()->isInactive());
    }

    public function test_an_administrador_cannot_deactivate_themselves_or_drop_their_own_role(): void
    {
        $admin = User::factory()->administrador()->create();
        User::factory()->administrador()->create();
        $team = Team::factory()->create();

        $this->signIn($admin)->from('/users')->post("/users/{$admin->id}/deactivate")
            ->assertSessionHas('error', __('users.errors.cannot_deactivate_self'));
        $this->signIn($admin)->from('/users')->put("/users/{$admin->id}", ['role' => 'empleado', 'team_ids' => [$team->id]])
            ->assertSessionHas('error', __('users.errors.cannot_change_own_role'));

        $this->assertSame(UserRole::Administrador, $admin->fresh()->roleEnum());
    }

    public function test_the_last_active_administrador_can_neither_be_deactivated_nor_demoted(): void
    {
        $last = User::factory()->administrador()->create();
        // Un jefe al que se le concede `admins.manage` directamente: asi el unico administrador activo es el objetivo.
        $actor = User::factory()->jefe()->create();
        $actor->givePermissionTo(PermissionName::AdminsManage->value);
        $service = app(UserService::class);

        try {
            $service->deactivate($actor, $last);
            $this->fail('debio impedir inactivar al ultimo administrador');
        } catch (BusinessRuleException $exception) {
            $this->assertSame(__('users.errors.last_admin'), $exception->getMessage());
        }

        try {
            $service->updateRoleAndTeams($actor, $last, UserRole::JefeZona, []);
            $this->fail('debio impedir degradar al ultimo administrador');
        } catch (BusinessRuleException $exception) {
            $this->assertSame(__('users.errors.last_admin'), $exception->getMessage());
        }

        $this->assertSame(UserRole::Administrador, $last->fresh()->roleEnum());
        $this->assertTrue($last->fresh()->isActive());
    }

    public function test_an_administrador_can_be_deactivated_while_another_remains(): void
    {
        $admin = User::factory()->administrador()->create();
        $other = User::factory()->administrador()->create();

        $this->signIn($admin)->post("/users/{$other->id}/deactivate")->assertSessionHasNoErrors();

        $this->assertTrue($other->fresh()->isInactive());
    }

    public function test_show_page_of_own_account_hides_the_deactivate_action(): void
    {
        $this->signIn($this->jefe)->get("/users/{$this->jefe->id}")
            ->assertOk()
            ->assertDontSee(route('users.deactivate', $this->jefe))
            ->assertSee(__('users.show.cannot_edit_self_hint'));
    }
}
