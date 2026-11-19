<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Models\Activity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/** Subequipos de estacion («RH Tulum» bajo RRHH): destino de eventos, alcance del coordinador central y comando. */
class StationSubteamTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private const SECRET = 'secreto-dashboard-a-tickets-de-prueba-0123456789';

    private Team $sub;

    private User $subCoord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();

        $this->sub = Team::factory()->create(['coordination_id' => $this->teamA->coordination_id, 'name' => 'RH Puerto Morelos']);
        $this->sub->forceFill(['parent_team_id' => $this->teamA->id, 'area' => 'rh', 'station_code' => '23'])->save();
        $this->subCoord = User::factory()->coordinador()->create(['team_id' => $this->sub->id]);
        $this->sub->update(['coordinator_id' => $this->subCoord->id]);

        config([
            'ecosistema.puente.secreto_entrada' => self::SECRET,
            'ecosistema.puente.ips' => [],
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.actividades.estaciones' => [],
            'ecosistema.actividades.eventos.asistencia_pendiente.equipo' => $this->teamA->id,
        ]);
    }

    private function send(string $station, string $ref): void
    {
        $body = json_encode(['evento' => 'asistencia_pendiente', 'estacion' => $station, 'ref' => $ref, 'titulo' => 'Asistencia pendiente de la estacion',
            'descripcion' => 'Falta capturar la asistencia de hoy en la estacion.', 'url' => 'https://dashboard.ejemplo.test/insumos/estaciones/3'], JSON_THROW_ON_ERROR);
        $nonce = Str::random(40);
        $date = now()->getTimestamp();
        $canonical = implode("\n", ['POST', '/interno/actividades', '', 'dashboard', (string) $date, $nonce, hash('sha256', $body)]);

        $this->call('POST', '/interno/actividades', [], [], [], $this->transformHeadersToServerVars([
            'X-Puente-Sistema' => 'dashboard', 'X-Puente-Fecha' => (string) $date, 'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonical, self::SECRET), 'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ]), $body)->assertCreated();
    }

    public function test_a_station_event_goes_to_the_station_subteam_and_other_stations_to_the_central_team(): void
    {
        $this->send('23', 'dashboard:asistencia:23:2026-11-20');
        $this->send('24', 'dashboard:asistencia:24:2026-11-20');

        $this->assertSame([$this->sub->id, $this->teamA->id], Activity::query()->orderBy('id')->pluck('team_id')->all());
    }

    public function test_a_subteam_without_coordinator_falls_back_to_the_central_team(): void
    {
        $this->sub->update(['coordinator_id' => null]);

        $this->send('23', 'dashboard:asistencia:23:2026-11-21');

        $this->assertSame($this->teamA->id, Activity::query()->sole()->team_id);
    }

    public function test_the_central_coordinator_reaches_the_subteams_but_a_central_employee_does_not(): void
    {
        $this->assertContains($this->sub->id, $this->coordA->scopedTeamIds());
        $this->assertContains($this->teamA->id, $this->coordA->scopedTeamIds());
        $this->assertNotContains($this->sub->id, $this->empA1->scopedTeamIds());
        $this->assertSame([$this->sub->id], $this->subCoord->scopedTeamIds());
    }

    public function test_the_sync_command_creates_missing_subteams_only_with_apply_and_is_idempotent(): void
    {
        config([
            'ecosistema.subequipos.areas' => ['rh' => ['prefijo' => 'RH', 'equipo' => $this->teamA->id]],
            'ecosistema.subequipos.estaciones' => ['23' => 'Puerto Morelos', '25' => 'Tulum'],
        ]);

        $this->artisan('ecosystem:sync-station-teams')->assertSuccessful();
        $this->assertSame(1, Team::query()->where('parent_team_id', $this->teamA->id)->count());

        $this->artisan('ecosystem:sync-station-teams --apply')->assertSuccessful();
        $this->artisan('ecosystem:sync-station-teams --apply')->assertSuccessful();

        $this->assertSame(2, Team::query()->where('parent_team_id', $this->teamA->id)->count());
        $this->assertSame('RH Tulum', Team::query()->where('station_code', '25')->value('name'));
    }
}
