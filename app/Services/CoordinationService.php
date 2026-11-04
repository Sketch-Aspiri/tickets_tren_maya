<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\Coordination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * CRUD de coordinaciones. La bitacora la genera el trait LogsActivity del modelo.
 */
final class CoordinationService
{
    /**
     * @return LengthAwarePaginator<int, Coordination>
     */
    public function paginate(): LengthAwarePaginator
    {
        return Coordination::query()
            ->with('station:id,name')
            ->withCount(['teams', 'bosses'])
            ->orderBy('name')
            ->paginate((int) config('tickets.categories_per_page'));
    }

    /**
     * @param  array{station_id: int, name: string, code: string, active: bool}  $data
     */
    public function create(array $data): Coordination
    {
        return Coordination::query()->create($this->attributes($data));
    }

    /**
     * @param  array{station_id: int, name: string, code: string, active: bool}  $data
     */
    public function update(Coordination $coordination, array $data): Coordination
    {
        $coordination->update($this->attributes($data));

        return $coordination;
    }

    /**
     * Una coordinacion con equipos, jefes o categorias propias no se borra: se desactiva.
     */
    public function delete(Coordination $coordination): void
    {
        if ($coordination->teams()->exists()) {
            throw BusinessRuleException::because('coordinations.errors.has_teams');
        }

        if ($coordination->bosses()->exists()) {
            throw BusinessRuleException::because('coordinations.errors.has_bosses');
        }

        if (Category::query()->where('coordination_id', $coordination->getKey())->exists()) {
            throw BusinessRuleException::because('coordinations.errors.has_categories');
        }

        $coordination->delete();
    }

    /**
     * @param  array{station_id: int, name: string, code: string, active: bool}  $data
     * @return array{station_id: int, name: string, code: string, active: bool}
     */
    private function attributes(array $data): array
    {
        return [
            'station_id' => $data['station_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'active' => $data['active'],
        ];
    }
}
