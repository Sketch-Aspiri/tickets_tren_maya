<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Models\Coordination;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class BossCoordinationsTest extends DatabaseTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->administrador()->create();
    }

    public function test_approving_a_jefe_assigns_several_coordinations(): void
    {
        $pending = User::factory()->pending()->create();
        [$a, $b] = Coordination::factory()->count(2)->create();

        $this->signIn($this->admin)->post("/users/{$pending->id}/approve", [
            'role' => 'jefe_zona',
            'coordination_ids' => [$a->id, $b->id],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $pending->fresh()->coordinations()->pluck('coordinations.id')->all());
        $this->assertTrue(Activity::query()->where('event', 'coordinations_changed')->where('subject_id', $pending->id)->exists());
    }

    public function test_updating_a_jefe_replaces_the_coordinations(): void
    {
        $jefe = User::factory()->jefe()->create();
        [$a, $b] = Coordination::factory()->count(2)->create();
        $jefe->coordinations()->sync([$a->id]);

        $this->signIn($this->admin)->put("/users/{$jefe->id}", ['role' => 'jefe_zona', 'coordination_ids' => [$b->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$b->id], $jefe->fresh()->coordinations()->pluck('coordinations.id')->all());
    }

    public function test_unknown_coordination_ids_fail_validation(): void
    {
        $jefe = User::factory()->jefe()->create();

        $this->signIn($this->admin)->put("/users/{$jefe->id}", ['role' => 'jefe_zona', 'coordination_ids' => [99999]])
            ->assertInvalid(['coordination_ids.0']);
    }

    public function test_coordinations_are_ignored_for_non_jefe_roles(): void
    {
        $pending = User::factory()->pending()->create();
        $coordination = Coordination::factory()->create();
        $team = Team::factory()->create();

        $this->signIn($this->admin)->post("/users/{$pending->id}/approve", [
            'role' => 'empleado',
            'team_ids' => [$team->id],
            'coordination_ids' => [$coordination->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $pending->fresh()->coordinations()->count());
    }

    public function test_changing_the_role_away_from_jefe_removes_the_coordinations(): void
    {
        User::factory()->jefe()->create(); // otro jefe activo: el sistema no puede quedarse sin ninguno
        $jefe = User::factory()->jefe()->create();
        $jefe->coordinations()->sync([Coordination::factory()->create()->id]);
        $team = Team::factory()->create();

        $this->signIn($this->admin)->put("/users/{$jefe->id}", ['role' => 'coordinador', 'team_ids' => [$team->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $jefe->fresh()->coordinations()->count());
    }

    public function test_user_detail_shows_the_coordinations_of_a_jefe(): void
    {
        $jefe = User::factory()->jefe()->create();
        $coordination = Coordination::factory()->create(['name' => 'Coordinación Norte']);
        $jefe->coordinations()->sync([$coordination->id]);

        $this->signIn($this->admin)->get("/users/{$jefe->id}")->assertOk()->assertSee('Coordinación Norte');
    }
}
