<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Models\Coordination;
use App\Models\Station;

/**
 * Donde viven en Tickets los subequipos de una estacion: la coordinacion de ESA estacion (edificio), no la del equipo
 * central. Reutiliza la coordinacion existente de la estacion (`ecosistema.subequipos.coordinaciones_existentes`) o crea
 * la estacion y su «Coordinación principal» (codigo EST-<codigo>) si no hay.
 */
final class StationPlacementService
{
    public function coordinationFor(string $code, string $stationName): Coordination
    {
        $own = 'EST-'.$code;

        $found = Coordination::query()->where('code', $own)->first();
        if ($found !== null) {
            return $found;
        }

        $existingCode = config("ecosistema.subequipos.coordinaciones_existentes.$code");
        if (is_string($existingCode) && $existingCode !== '') {
            $existing = Coordination::query()->where('code', $existingCode)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $station = Station::query()->firstOrCreate(['name' => $stationName], ['active' => true]);

        return Coordination::query()->firstOrCreate(
            ['station_id' => $station->getKey(), 'name' => 'Coordinación principal'],
            ['code' => $own, 'active' => true],
        );
    }
}
