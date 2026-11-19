<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Models\Coordination;
use App\Models\Station;
use App\Models\Team;
use Tests\DatabaseTestCase;

/** Los subequipos de estacion viven en la coordinacion de su estacion, no en la del equipo central. */
class StationPlacementTest extends DatabaseTestCase
{
    public function test_moves_station_subteams_to_their_station_coordination_creating_it_when_needed(): void
    {
        config(['ecosistema.subequipos.estaciones' => ['25' => 'Tulum', '28' => 'Limones'], 'ecosistema.subequipos.coordinaciones_existentes' => ['28' => 'CGGIF']]);

        $zonal = Coordination::factory()->create();
        $limones = Coordination::factory()->create(['code' => 'CGGIF']);
        $central = Team::factory()->create(['coordination_id' => $zonal->id]);
        $tulum = Team::query()->forceCreate(['coordination_id' => $zonal->id, 'parent_team_id' => $central->id, 'name' => 'RH Tulum', 'area' => 'rh', 'station_code' => '25']);
        $lim = Team::query()->forceCreate(['coordination_id' => $zonal->id, 'parent_team_id' => $central->id, 'name' => 'RH Limones', 'area' => 'rh', 'station_code' => '28']);

        $this->artisan('ecosystem:place-station-teams')->assertSuccessful();
        $this->assertSame($zonal->id, $tulum->fresh()->coordination_id, 'sin --apply no mueve nada');

        $this->artisan('ecosystem:place-station-teams', ['--apply' => true])->assertSuccessful();

        $new = Coordination::query()->where('code', 'EST-25')->firstOrFail();
        $this->assertSame('Tulum', Station::query()->findOrFail($new->station_id)->name);
        $this->assertSame($new->id, $tulum->fresh()->coordination_id);
        $this->assertSame($limones->id, $lim->fresh()->coordination_id);
        $this->assertSame($zonal->id, $central->fresh()->coordination_id, 'el equipo central no se mueve');

        // Idempotente: no duplica estaciones ni coordinaciones.
        $this->artisan('ecosystem:place-station-teams', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, Coordination::query()->where('code', 'EST-25')->count());
    }
}
