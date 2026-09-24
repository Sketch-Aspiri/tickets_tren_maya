<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class TeamCrudTest extends DatabaseTestCase
{
    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jefe = User::factory()->jefe()->create();
    }

    public function test_jefe_creates_a_team_and_it_is_audited(): void
    {
        $this->signIn($this->jefe)->post('/teams', ['name' => 'Logística'])
            ->assertRedirect(route('teams.index'))
            ->assertSessionHas('status', 'team-created');

        $team = Team::query()->where('name', 'Logística')->firstOrFail();
        $this->assertNull($team->coordinator_id);

        $activity = Activity::query()->where('subject_type', Team::class)->where('subject_id', $team->id)->where('event', 'created')->firstOrFail();
        $this->assertSame($this->jefe->id, $activity->causer_id);
        $this->assertSame('Logística', $activity->attribute_changes['attributes']['name']);
    }

    public function test_team_can_be_created_without_coordinator(): void
    {
        $this->signIn($this->jefe)->post('/teams', ['name' => 'Sin Coordinador'])->assertSessionHasNoErrors();

        $this->assertNull(Team::query()->where('name', 'Sin Coordinador')->firstOrFail()->coordinator_id);
    }

    public function test_index_lists_teams_with_member_counts_without_n_plus_one(): void
    {
        $teams = Team::factory()->count(3)->create();
        foreach ($teams as $team) {
            User::factory()->count(2)->empleado()->create(['team_id' => $team->id]);
        }

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->signIn($this->jefe)->get('/teams')->assertOk();

            return count(DB::getQueryLog());
        };

        $count();
        $few = $count();
        Team::factory()->count(6)->create();
        $many = $count();

        $this->assertSame($few, $many);
    }

    public function test_jefe_updates_a_team_and_the_change_is_audited_with_old_values(): void
    {
        $team = Team::factory()->create(['name' => 'Nombre Viejo']);
        $coordinador = User::factory()->coordinador()->create(['team_id' => $team->id]);

        $this->signIn($this->jefe)->put("/teams/{$team->id}", ['name' => 'Nombre Nuevo', 'coordinator_id' => $coordinador->id])
            ->assertRedirect(route('teams.index'));

        $fresh = $team->fresh();
        $this->assertSame('Nombre Nuevo', $fresh->name);
        $this->assertSame($coordinador->id, $fresh->coordinator_id);

        $activity = Activity::query()->where('subject_type', Team::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('Nombre Viejo', $activity->attribute_changes['old']['name']);
        $this->assertSame('Nombre Nuevo', $activity->attribute_changes['attributes']['name']);
    }

    public function test_updating_a_team_can_keep_its_own_name(): void
    {
        $team = Team::factory()->create(['name' => 'Mismo Nombre']);

        $this->signIn($this->jefe)->put("/teams/{$team->id}", ['name' => 'Mismo Nombre'])->assertSessionHasNoErrors();
    }

    public function test_jefe_deletes_an_empty_team_and_it_is_audited(): void
    {
        $team = Team::factory()->create();

        $this->signIn($this->jefe)->delete("/teams/{$team->id}")->assertRedirect(route('teams.index'));

        $this->assertModelMissing($team);
        $this->assertDatabaseHas('activity_log', ['subject_type' => Team::class, 'event' => 'deleted', 'subject_id' => $team->id]);
    }

    public function test_team_with_members_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();
        User::factory()->empleado()->create(['team_id' => $team->id]);

        $this->signIn($this->jefe)->from('/teams')->delete("/teams/{$team->id}")
            ->assertRedirect('/teams')
            ->assertSessionHas('error', __('teams.errors.has_members'));

        $this->assertModelExists($team);
    }

    public function test_team_validation(): void
    {
        Team::factory()->create(['name' => 'Existente']);

        $this->signIn($this->jefe)->post('/teams', [])->assertInvalid(['name']);
        $this->signIn($this->jefe)->post('/teams', ['name' => 'Existente'])->assertInvalid(['name']);
        $this->signIn($this->jefe)->post('/teams', ['name' => str_repeat('a', 256)])->assertInvalid(['name']);
        $this->signIn($this->jefe)->post('/teams', ['name' => 'Ok', 'coordinator_id' => 'abc'])->assertInvalid(['coordinator_id']);
        $this->signIn($this->jefe)->post('/teams', ['name' => 'Ok', 'coordinator_id' => 99999])->assertInvalid(['coordinator_id']);
    }

    public function test_coordinator_must_be_an_active_coordinador_member_of_the_team(): void
    {
        $team = Team::factory()->create();
        $empleado = User::factory()->empleado()->create(['team_id' => $team->id]);
        $jefe = User::factory()->jefe()->create();
        $inactiveCoordinador = User::factory()->coordinador()->inactive()->create(['team_id' => $team->id]);
        $pending = User::factory()->pending()->create();

        foreach ([$empleado, $jefe, $inactiveCoordinador, $pending] as $candidate) {
            $this->signIn($this->jefe)->put("/teams/{$team->id}", ['name' => $team->name, 'coordinator_id' => $candidate->id])
                ->assertInvalid(['coordinator_id']);
        }

        $this->assertNull($team->fresh()->coordinator_id);
    }

    public function test_mass_assignment_ignores_unexpected_fields(): void
    {
        $this->signIn($this->jefe)->post('/teams', ['name' => 'Seguro', 'id' => 9999, 'created_at' => '2000-01-01'])
            ->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Seguro')->firstOrFail();
        $this->assertNotSame(9999, $team->id);
        $this->assertTrue($team->created_at->year >= 2024);
    }

    public function test_team_model_has_explicit_fillable_and_no_open_guard(): void
    {
        $team = new Team;
        $user = new User;

        $this->assertSame(['name', 'coordinator_id'], $team->getFillable());
        $this->assertSame(['name', 'email', 'password'], $user->getFillable());
        $this->assertNotSame([], $team->getGuarded());
    }

    public function test_deleting_a_coordinator_user_nulls_the_team_reference_at_database_level(): void
    {
        $coordinador = User::factory()->coordinador()->create();
        $team = Team::factory()->create(['coordinator_id' => $coordinador->id]);

        $coordinador->delete();

        $this->assertNull($team->fresh()->coordinator_id);
    }
}
