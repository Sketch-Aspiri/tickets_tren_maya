<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Models\Category;
use App\Models\Coordination;
use App\Models\Station;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class OrganizationCrudTest extends DatabaseTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->administrador()->create();
    }

    public function test_admin_creates_a_station_and_it_is_audited(): void
    {
        $this->signIn($this->admin)->post('/stations', ['name' => 'Estación Mérida', 'active' => '1'])
            ->assertRedirect(route('stations.index'))
            ->assertSessionHas('status', 'station-created');

        $station = Station::query()->where('name', 'Estación Mérida')->firstOrFail();
        $this->assertTrue($station->active);

        $log = Activity::query()->where('subject_type', $station->getMorphClass())->where('subject_id', $station->id)->where('event', 'created')->firstOrFail();
        $this->assertSame($this->admin->id, $log->causer_id);
    }

    public function test_admin_updates_and_lists_stations(): void
    {
        $station = Station::factory()->create(['name' => 'Vieja']);

        $this->signIn($this->admin)->put("/stations/{$station->id}", ['name' => 'Nueva', 'active' => '0'])
            ->assertRedirect(route('stations.index'));

        $this->assertSame('Nueva', $station->fresh()->name);
        $this->assertFalse($station->fresh()->active);
        $this->signIn($this->admin)->get('/stations')->assertOk()->assertSee('Nueva');
    }

    public function test_station_name_must_be_unique(): void
    {
        Station::factory()->create(['name' => 'Repetida']);

        $this->signIn($this->admin)->post('/stations', ['name' => 'Repetida'])->assertInvalid(['name']);
    }

    public function test_station_with_coordinations_cannot_be_deleted(): void
    {
        $coordination = Coordination::factory()->create();

        $this->signIn($this->admin)->delete("/stations/{$coordination->station_id}")
            ->assertSessionHas('error', __('stations.errors.has_coordinations'));

        $this->assertModelExists($coordination->station);
    }

    public function test_empty_station_can_be_deleted(): void
    {
        $station = Station::factory()->create();

        $this->signIn($this->admin)->delete("/stations/{$station->id}")->assertRedirect(route('stations.index'));

        $this->assertModelMissing($station);
    }

    public function test_admin_creates_a_coordination_with_uppercase_code(): void
    {
        $station = Station::factory()->create();

        $this->signIn($this->admin)->post('/coordinations', [
            'station_id' => $station->id,
            'name' => 'Tecnologías',
            'code' => 'ti-mer',
        ])->assertRedirect(route('coordinations.index'));

        $coordination = Coordination::query()->where('name', 'Tecnologías')->firstOrFail();
        $this->assertSame('TI-MER', $coordination->code);
        $this->assertSame($station->id, $coordination->station_id);
    }

    public function test_coordination_validation(): void
    {
        $existing = Coordination::factory()->create(['name' => 'Operaciones', 'code' => 'OP-1']);

        $this->signIn($this->admin)->post('/coordinations', [])->assertInvalid(['station_id', 'name', 'code']);
        $this->signIn($this->admin)->post('/coordinations', ['station_id' => 99999, 'name' => 'X', 'code' => 'X1'])->assertInvalid(['station_id']);
        $this->signIn($this->admin)->post('/coordinations', ['station_id' => $existing->station_id, 'name' => 'Operaciones', 'code' => 'NUEVO'])->assertInvalid(['name']);
        $this->signIn($this->admin)->post('/coordinations', ['station_id' => $existing->station_id, 'name' => 'Otra', 'code' => 'op-1'])->assertInvalid(['code']);
        $this->signIn($this->admin)->post('/coordinations', ['station_id' => $existing->station_id, 'name' => 'Otra', 'code' => 'con espacio'])->assertInvalid(['code']);
    }

    public function test_the_same_coordination_name_is_allowed_in_another_station(): void
    {
        $existing = Coordination::factory()->create(['name' => 'Operaciones']);
        $otherStation = Station::factory()->create();

        $this->signIn($this->admin)->post('/coordinations', [
            'station_id' => $otherStation->id,
            'name' => 'Operaciones',
            'code' => 'OP-2',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Coordination::query()->where('name', 'Operaciones')->count());
        $this->assertNotSame($existing->station_id, $otherStation->id);
    }

    public function test_coordination_keeps_its_own_name_and_code_on_update(): void
    {
        $coordination = Coordination::factory()->create(['name' => 'Fija', 'code' => 'FIJA']);

        $this->signIn($this->admin)->put("/coordinations/{$coordination->id}", [
            'station_id' => $coordination->station_id,
            'name' => 'Fija',
            'code' => 'FIJA',
            'active' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($coordination->fresh()->active);
    }

    public function test_coordination_with_teams_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();

        $this->signIn($this->admin)->delete("/coordinations/{$team->coordination_id}")
            ->assertSessionHas('error', __('coordinations.errors.has_teams'));

        $this->assertModelExists($team->coordination);
    }

    public function test_coordination_with_bosses_or_categories_cannot_be_deleted(): void
    {
        $withBoss = Coordination::factory()->create();
        $withBoss->bosses()->syncWithoutDetaching([User::factory()->jefe()->create()->id]);
        $withCategory = Coordination::factory()->create();
        $withCategory->bosses()->detach();
        Category::factory()->create(['coordination_id' => $withCategory->id]);

        $this->signIn($this->admin)->delete("/coordinations/{$withBoss->id}")
            ->assertSessionHas('error', __('coordinations.errors.has_bosses'));
        $this->signIn($this->admin)->delete("/coordinations/{$withCategory->id}")
            ->assertSessionHas('error', __('coordinations.errors.has_categories'));
    }

    public function test_empty_coordination_can_be_deleted(): void
    {
        $coordination = Coordination::factory()->create();

        $this->signIn($this->admin)->delete("/coordinations/{$coordination->id}")->assertRedirect(route('coordinations.index'));

        $this->assertModelMissing($coordination);
    }

    public function test_team_requires_an_active_coordination(): void
    {
        $inactive = Coordination::factory()->inactive()->create();

        $this->signIn($this->admin)->post('/teams', ['name' => 'Sin coord'])->assertInvalid(['coordination_id']);
        $this->signIn($this->admin)->post('/teams', ['name' => 'Inactiva', 'coordination_id' => $inactive->id])->assertInvalid(['coordination_id']);
    }

    public function test_team_names_are_unique_only_within_a_coordination(): void
    {
        $team = Team::factory()->create(['name' => 'Soporte']);
        $other = Coordination::factory()->create();

        $this->signIn($this->admin)->post('/teams', ['name' => 'Soporte', 'coordination_id' => $team->coordination_id])->assertInvalid(['name']);
        $this->signIn($this->admin)->post('/teams', ['name' => 'Soporte', 'coordination_id' => $other->id])->assertSessionHasNoErrors();
    }

    public function test_models_have_explicit_fillable_and_are_in_the_morph_map(): void
    {
        $this->assertSame(['name', 'active'], (new Station)->getFillable());
        $this->assertSame(['station_id', 'name', 'code', 'active'], (new Coordination)->getFillable());
        $this->assertSame('station', (new Station)->getMorphClass());
        $this->assertSame('coordination', (new Coordination)->getMorphClass());
    }

    public function test_mass_assignment_ignores_unexpected_fields(): void
    {
        $this->signIn($this->admin)->post('/stations', ['name' => 'Segura', 'id' => 9999, 'created_at' => '2000-01-01'])
            ->assertSessionHasNoErrors();

        $station = Station::query()->where('name', 'Segura')->firstOrFail();
        $this->assertNotSame(9999, $station->id);
        $this->assertTrue($station->created_at->year >= 2024);
    }
}
