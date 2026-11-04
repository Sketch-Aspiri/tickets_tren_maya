<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Station;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * CRUD de estaciones. La bitacora la genera el trait LogsActivity del modelo.
 */
final class StationService
{
    /**
     * @return LengthAwarePaginator<int, Station>
     */
    public function paginate(): LengthAwarePaginator
    {
        return Station::query()
            ->withCount('coordinations')
            ->orderBy('name')
            ->paginate((int) config('tickets.categories_per_page'));
    }

    /**
     * @param  array{name: string, active: bool}  $data
     */
    public function create(array $data): Station
    {
        return Station::query()->create(['name' => $data['name'], 'active' => $data['active']]);
    }

    /**
     * @param  array{name: string, active: bool}  $data
     */
    public function update(Station $station, array $data): Station
    {
        $station->update(['name' => $data['name'], 'active' => $data['active']]);

        return $station;
    }

    /**
     * Una estacion con coordinaciones no se borra: se desactiva.
     */
    public function delete(Station $station): void
    {
        if ($station->coordinations()->exists()) {
            throw BusinessRuleException::because('stations.errors.has_coordinations');
        }

        $station->delete();
    }
}
