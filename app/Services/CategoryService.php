<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de categorías (solo jefe de zona). La bitácora de create/update/delete la genera el
 * trait LogsActivity del modelo.
 */
final class CategoryService
{
    /**
     * @return LengthAwarePaginator<int, Category>
     */
    public function paginate(User $actor): LengthAwarePaginator
    {
        return Category::query()
            ->manageableBy($actor)
            ->with('coordination:id,name,station_id', 'coordination.station:id,name')
            ->withCount('tickets')
            // Agrupadas: primero las generales, luego por edificio y coordinacion (la vista pone un encabezado).
            ->orderByRaw('case when categories.coordination_id is null then 0 else 1 end')
            ->orderBy(DB::table('coordinations')->join('stations', 'stations.id', '=', 'coordinations.station_id')->select('stations.name')->whereColumn('coordinations.id', 'categories.coordination_id'))
            ->orderBy(DB::table('coordinations')->select('name')->whereColumn('coordinations.id', 'categories.coordination_id'))
            ->orderBy('categories.coordination_id')
            ->orderBy('categories.name')
            ->paginate((int) config('tickets.categories_per_page'));
    }

    /**
     * Categorías activas para los formularios. Si el registro ya usa una inactiva, se conserva en la lista para poder
     * editarlo sin perder su categoría. Cuál conjunto:
     * - `$coordinationId` (registro ligado a un equipo, o correo de una coordinación): las generales + las de ESA
     *   coordinación;
     * - `$alsoCoordinationId`: suma las de esa coordinación (ticket nuevo: las de Soporte del sistema, a la que
     *   todos pueden escribir);
     * - si no: las que el usuario puede ver (generales + las de sus coordinaciones); la cascada del formulario
     *   deja solo las de la coordinación elegida y el Form Request lo exige (`CategoryFitsTeam`).
     * Viajan con `coordination.station` para agruparlas («General» / «Edificio › Coordinación»).
     *
     * @return Collection<int, Category>
     */
    public function selectable(User $actor, ?int $keepCategoryId = null, ?int $coordinationId = null, ?int $alsoCoordinationId = null): Collection
    {
        return Category::query()
            ->where(fn ($scope) => $scope
                ->when(
                    $coordinationId !== null,
                    fn ($inner) => $inner->where(fn ($fit) => $fit->whereNull('categories.coordination_id')->orWhere('categories.coordination_id', $coordinationId)),
                    fn ($inner) => $inner->visibleTo($actor),
                )
                ->when($alsoCoordinationId, fn ($also, int $id) => $also->orWhere('categories.coordination_id', $id))
                ->when($keepCategoryId, fn ($keep, int $id) => $keep->orWhere('categories.id', $id)))
            ->where(fn ($query) => $query->where('active', true)->when($keepCategoryId, fn ($inner, int $id) => $inner->orWhere('id', $id)))
            ->with(['coordination:id,name,station_id', 'coordination.station:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'active', 'coordination_id']);
    }

    /**
     * Categorías para FILTRAR listados (activas o no): las que el usuario puede ver, agrupables por coordinación.
     *
     * @return Collection<int, Category>
     */
    public function filterable(User $actor): Collection
    {
        return Category::query()
            ->visibleTo($actor)
            ->with(['coordination:id,name,station_id', 'coordination.station:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'active', 'coordination_id']);
    }

    /**
     * @param  array{name: string, coordination_id?: ?int, active?: bool}  $data
     */
    public function create(array $data): Category
    {
        return Category::query()->create([
            'coordination_id' => $data['coordination_id'] ?? null,
            'name' => $data['name'],
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * @param  array{name: string, coordination_id?: ?int, active?: bool}  $data
     */
    public function update(Category $category, array $data): Category
    {
        $category->update([
            'coordination_id' => array_key_exists('coordination_id', $data) ? $data['coordination_id'] : $category->coordination_id,
            'name' => $data['name'],
            'active' => $data['active'] ?? $category->active,
        ]);

        return $category;
    }

    /**
     * Una categoría con tickets (incluso eliminados) no se borra: se desactiva para conservar el historial.
     */
    public function delete(Category $category): void
    {
        if ($category->tickets()->withTrashed()->exists()) {
            throw BusinessRuleException::because('categories.errors.has_tickets');
        }

        $category->delete();
    }
}
