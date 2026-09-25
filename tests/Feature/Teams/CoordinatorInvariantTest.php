<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\User;
use App\Rules\ActiveCoordinator;
use Illuminate\Support\Facades\Validator;
use Tests\DatabaseTestCase;

/**
 * Invariante: un coordinador solo coordina equipos a los que pertenece (`team_user` es la fuente de verdad).
 * Puede pertenecer, y por tanto coordinar, varios equipos; cada equipo tiene un unico coordinador.
 */
class CoordinatorInvariantTest extends DatabaseTestCase
{
    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jefe = User::factory()->jefe()->create();
    }

    /**
     * @return array{0: User, 1: Team} coordinador y el equipo al que pertenece y que coordina
     */
    private function coordinatorOfOwnTeam(): array
    {
        $coordinador = User::factory()->coordinador()->create();
        $team = Team::query()->findOrFail($coordinador->teamIds()[0]);
        $team->update(['coordinator_id' => $coordinador->id]);

        return [$coordinador, $team];
    }

    public function test_moving_a_coordinator_to_another_team_releases_the_team_they_left(): void
    {
        [$coordinador, $oldTeam] = $this->coordinatorOfOwnTeam();
        $newTeam = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$coordinador->id}", ['role' => 'coordinador', 'team_ids' => [$newTeam->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$newTeam->id], $coordinador->teamIds());
        $this->assertNull($oldTeam->fresh()->coordinator_id);
        $this->assertDatabaseHas('activity_log', ['subject_type' => (new Team)->getMorphClass(), 'subject_id' => $oldTeam->id, 'event' => 'updated']);
    }

    public function test_the_new_team_is_not_assigned_automatically_when_a_coordinator_moves_in(): void
    {
        [$coordinador] = $this->coordinatorOfOwnTeam();
        $newTeam = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$coordinador->id}", ['role' => 'coordinador', 'team_ids' => [$newTeam->id]]);

        $this->assertNull($newTeam->fresh()->coordinator_id);
    }

    public function test_keeping_the_same_team_keeps_the_coordination(): void
    {
        [$coordinador, $team] = $this->coordinatorOfOwnTeam();

        $this->signIn($this->jefe)->put("/users/{$coordinador->id}", ['role' => 'coordinador', 'team_ids' => [$team->id]]);

        $this->assertSame($coordinador->id, $team->fresh()->coordinator_id);
    }

    public function test_adding_a_team_keeps_the_coordination_of_the_existing_one(): void
    {
        [$coordinador, $team] = $this->coordinatorOfOwnTeam();
        $second = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$coordinador->id}", ['role' => 'coordinador', 'team_ids' => [$team->id, $second->id]])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$team->id, $second->id], $coordinador->teamIds());
        $this->assertSame($coordinador->id, $team->fresh()->coordinator_id);
        $this->assertNull($second->fresh()->coordinator_id, 'el equipo nuevo no se asigna solo');
    }

    public function test_leaving_only_one_of_two_teams_releases_just_that_team(): void
    {
        [$coordinador, $first] = $this->coordinatorOfOwnTeam();
        $second = Team::factory()->create(['coordinator_id' => null]);
        $coordinador->teams()->attach($second->id);
        $second->update(['coordinator_id' => $coordinador->id]);

        $this->signIn($this->jefe)->put("/users/{$coordinador->id}", ['role' => 'coordinador', 'team_ids' => [$second->id]]);

        $this->assertNull($first->fresh()->coordinator_id);
        $this->assertSame($coordinador->id, $second->fresh()->coordinator_id);
    }

    public function test_demoting_a_coordinator_to_empleado_releases_their_team(): void
    {
        [$coordinador, $team] = $this->coordinatorOfOwnTeam();

        $this->signIn($this->jefe)->put("/users/{$coordinador->id}", ['role' => 'empleado', 'team_ids' => [$team->id]]);

        $this->assertNull($team->fresh()->coordinator_id);
    }

    public function test_deactivating_a_coordinator_releases_their_team(): void
    {
        [$coordinador, $team] = $this->coordinatorOfOwnTeam();

        $this->signIn($this->jefe)->post("/users/{$coordinador->id}/deactivate");

        $this->assertNull($team->fresh()->coordinator_id);
    }

    public function test_team_update_accepts_a_coordinator_who_belongs_to_the_team(): void
    {
        $team = Team::factory()->create();
        $coordinador = User::factory()->coordinador()->create(['team_id' => $team->id]);

        $this->signIn($this->jefe)->put("/teams/{$team->id}", ['name' => $team->name, 'coordinator_id' => $coordinador->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($coordinador->id, $team->fresh()->coordinator_id);
    }

    public function test_team_update_rejects_a_coordinator_from_another_team(): void
    {
        $team = Team::factory()->create();
        $foreignCoordinador = User::factory()->coordinador()->create();

        $this->signIn($this->jefe)->put("/teams/{$team->id}", ['name' => $team->name, 'coordinator_id' => $foreignCoordinador->id])
            ->assertInvalid(['coordinator_id']);

        $this->assertNull($team->fresh()->coordinator_id);
    }

    public function test_a_coordinator_cannot_coordinate_a_team_they_do_not_belong_to(): void
    {
        [$coordinador] = $this->coordinatorOfOwnTeam();
        $otherTeam = Team::factory()->create();

        $this->signIn($this->jefe)->put("/teams/{$otherTeam->id}", ['name' => $otherTeam->name, 'coordinator_id' => $coordinador->id])
            ->assertInvalid(['coordinator_id']);
    }

    public function test_a_coordinator_can_coordinate_several_teams_they_belong_to(): void
    {
        [$coordinador, $first] = $this->coordinatorOfOwnTeam();
        $second = Team::factory()->create();
        $coordinador->teams()->attach($second->id);

        $this->signIn($this->jefe)->put("/teams/{$second->id}", ['name' => $second->name, 'coordinator_id' => $coordinador->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($coordinador->id, $first->fresh()->coordinator_id);
        $this->assertSame($coordinador->id, $second->fresh()->coordinator_id);
    }

    public function test_a_new_team_has_no_members_so_it_cannot_be_created_with_a_coordinator(): void
    {
        $coordinador = User::factory()->coordinador()->create();

        $this->signIn($this->jefe)->post('/teams', ['name' => 'Nuevo', 'coordinator_id' => $coordinador->id])
            ->assertInvalid(['coordinator_id']);

        $this->assertDatabaseMissing('teams', ['name' => 'Nuevo']);
    }

    public function test_active_coordinator_rule_requires_active_coordinador_role_and_membership(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->coordinador()->create(['team_id' => $team->id]);
        $inactiveMember = User::factory()->coordinador()->inactive()->create(['team_id' => $team->id]);
        $empleadoMember = User::factory()->empleado()->create(['team_id' => $team->id]);
        $outsider = User::factory()->coordinador()->create();
        $alsoMember = User::factory()->coordinador()->create();
        $alsoMember->teams()->attach($team->id);

        $passes = fn (mixed $value): bool => Validator::make(['coordinator_id' => $value], ['coordinator_id' => [new ActiveCoordinator($team)]])->passes();

        $this->assertTrue($passes($member->id));
        $this->assertTrue($passes($alsoMember->id), 'pertenecer a otros equipos ademas de este no lo descalifica');
        $this->assertFalse($passes($inactiveMember->id));
        $this->assertFalse($passes($empleadoMember->id));
        $this->assertFalse($passes($outsider->id));
        $this->assertFalse($passes('abc'));
        $this->assertFalse($passes(99999));
    }

    public function test_coordinator_options_on_edit_only_list_members_of_that_team(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->coordinador()->create(['team_id' => $team->id, 'name' => 'Miembro Coordinador']);
        User::factory()->coordinador()->create(['name' => 'Ajeno Coordinador']);

        $this->signIn($this->jefe)->get("/teams/{$team->id}/edit")
            ->assertOk()
            ->assertSee('Miembro Coordinador')
            ->assertDontSee('Ajeno Coordinador');
    }
}
