<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Equipos que nacen en el Dashboard: un equipo central por area (clave) y un subequipo por estacion que lo tenga.
 * Idempotente y solo agrega: nunca borra ni renombra equipos existentes.
 */
final class StationTeamService
{
    /**
     * @param  list<array{codigo: string, nombre: string}>  $stations
     * @return array{equipo_central_id: int, creados: int}
     */
    public function ensure(string $area, string $name, array $stations): array
    {
        return DB::transaction(function () use ($area, $name, $stations): array {
            $central = $this->central($area) ?? $this->createCentral($area, $name);
            $prefix = (string) (config("ecosistema.subequipos.areas.$area.prefijo") ?: $name);
            $created = 0;

            foreach ($stations as $station) {
                $exists = Team::query()
                    ->where('parent_team_id', $central->getKey())
                    ->where('station_code', $station['codigo'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                Team::query()->forceCreate([
                    'coordination_id' => $central->coordination_id,
                    'parent_team_id' => $central->getKey(),
                    'name' => $prefix.' '.$station['nombre'],
                    'area' => $area,
                    'station_code' => $station['codigo'],
                ]);
                $created++;
            }

            return ['equipo_central_id' => (int) $central->getKey(), 'creados' => $created];
        });
    }

    private function central(string $area): ?Team
    {
        $own = Team::query()->whereNull('parent_team_id')->where('area', $area)->first();

        if ($own !== null) {
            return $own;
        }

        // Las siete areas originales se enlazan con su equipo central por configuracion (ECOSISTEMA_SUBEQ_*).
        $configured = config("ecosistema.subequipos.areas.$area.equipo");

        return ($configured !== null && $configured !== '' && ctype_digit((string) $configured))
            ? Team::query()->whereNull('parent_team_id')->find((int) $configured)
            : null;
    }

    private function createCentral(string $area, string $name): Team
    {
        $coordination = config('ecosistema.subequipos.coordinacion_nuevos')
            ?: Team::query()->whereNull('parent_team_id')->whereNotNull('coordination_id')->orderBy('id')->value('coordination_id');

        if (! $coordination) {
            throw new RuntimeException('No hay una coordinacion donde crear el equipo central.');
        }

        return Team::query()->forceCreate([
            'coordination_id' => (int) $coordination,
            'name' => $name,
            'area' => $area,
        ]);
    }
}
