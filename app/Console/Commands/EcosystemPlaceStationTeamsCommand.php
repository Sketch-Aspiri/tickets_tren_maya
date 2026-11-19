<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Coordination;
use App\Models\Team;
use App\Services\Ecosystem\StationPlacementService;
use Illuminate\Console\Command;

/**
 * Mueve los subequipos de estacion a la coordinacion de su estacion (la crea si falta). Idempotente; sin --apply
 * solo muestra lo que haria.
 */
class EcosystemPlaceStationTeamsCommand extends Command
{
    protected $signature = 'ecosystem:place-station-teams {--apply : Mover los subequipos y crear las coordinaciones que falten}';

    protected $description = 'Coloca cada subequipo de estacion en la coordinacion de su estacion';

    public function handle(StationPlacementService $placement): int
    {
        $stations = (array) config('ecosistema.subequipos.estaciones');
        $moved = 0;

        foreach ($stations as $code => $name) {
            $code = (string) $code;
            $teams = Team::query()->whereNotNull('parent_team_id')->where('station_code', $code)->get();

            if ($teams->isEmpty()) {
                continue;
            }

            if (! $this->option('apply')) {
                $existing = Coordination::query()->where('code', 'EST-'.$code)->exists()
                    || (config("ecosistema.subequipos.coordinaciones_existentes.$code") !== null);
                $this->line("· {$name}: {$teams->count()} subequipos → ".($existing ? 'coordinación existente' : 'coordinación nueva (se crea)'));

                continue;
            }

            $coordination = $placement->coordinationFor($code, (string) $name);

            foreach ($teams as $team) {
                if ((int) $team->coordination_id === (int) $coordination->getKey()) {
                    continue;
                }
                $team->forceFill(['coordination_id' => $coordination->getKey()])->save();
                $moved++;
            }
            $this->line("· {$name}: en «{$coordination->name}» (id {$coordination->getKey()})");
        }

        $this->info($this->option('apply') ? "Subequipos movidos: {$moved}." : 'Usa --apply para moverlos.');

        return self::SUCCESS;
    }
}
