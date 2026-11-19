<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Crea los subequipos de estacion («RH Tulum» bajo RRHH) de las areas configuradas en
 * `ecosistema.subequipos`. Idempotente: no toca los que ya existen. Sin --apply solo muestra lo que haria.
 */
class EcosystemSyncStationTeamsCommand extends Command
{
    protected $signature = 'ecosystem:sync-station-teams {--apply : Crear los subequipos que faltan}';

    protected $description = 'Crea los subequipos de estacion bajo los equipos centrales configurados (ECOSISTEMA_SUBEQ_*)';

    public function handle(): int
    {
        $stations = (array) config('ecosistema.subequipos.estaciones');
        $created = 0;
        $missing = 0;

        foreach ((array) config('ecosistema.subequipos.areas') as $area => $rule) {
            $central = $this->central($rule['equipo'] ?? null);

            if ($central === null) {
                $this->line("· {$area}: sin equipo central configurado (se omite)");

                continue;
            }

            foreach ($stations as $code => $stationName) {
                $code = (string) $code;
                $exists = Team::query()->where('parent_team_id', $central->getKey())->where('station_code', $code)->exists();

                if ($exists) {
                    continue;
                }

                $missing++;
                $name = $rule['prefijo'].' '.$stationName;
                $this->line(($this->option('apply') ? 'Creando' : 'Falta').": {$name} (bajo {$central->name})");

                if ($this->option('apply')) {
                    Team::query()->create([
                        'coordination_id' => $central->coordination_id,
                        'parent_team_id' => $central->getKey(),
                        'name' => $name,
                        'area' => (string) $area,
                        'station_code' => $code,
                    ]);
                    $created++;
                }
            }
        }

        $this->info($this->option('apply') ? "Subequipos creados: {$created}." : "Subequipos por crear: {$missing} (usa --apply).");

        return self::SUCCESS;
    }

    private function central(mixed $target): ?Team
    {
        if ($target === null || $target === '') {
            return null;
        }

        if (is_int($target) || (is_string($target) && ctype_digit($target))) {
            return Team::query()->whereNull('parent_team_id')->find((int) $target);
        }

        $matches = Team::query()->whereNull('parent_team_id')->where('name', (string) $target)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
