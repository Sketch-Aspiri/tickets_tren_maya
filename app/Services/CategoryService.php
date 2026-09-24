<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD de categorías (solo jefe de zona). La bitácora de create/update/delete la genera el
 * trait LogsActivity del modelo.
 */
final class CategoryService
{
    /**
     * @return LengthAwarePaginator<int, Category>
     */
    public function paginate(): LengthAwarePaginator
    {
        return Category::query()
            ->withCount('tickets')
            ->orderBy('name')
            ->paginate((int) config('tickets.categories_per_page'));
    }

    /**
     * Categorías activas para los formularios de ticket. Si el ticket ya usa una inactiva, se conserva
     * en la lista para poder editarlo sin perder su categoría.
     *
     * @return Collection<int, Category>
     */
    public function selectable(?int $keepCategoryId = null): Collection
    {
        return Category::query()
            ->where(fn ($query) => $query->where('active', true)->when($keepCategoryId, fn ($inner, int $id) => $inner->orWhere('id', $id)))
            ->orderBy('name')
            ->get(['id', 'name', 'active']);
    }

    /**
     * @param  array{name: string, active?: bool}  $data
     */
    public function create(array $data): Category
    {
        return Category::query()->create([
            'name' => $data['name'],
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * @param  array{name: string, active?: bool}  $data
     */
    public function update(Category $category, array $data): Category
    {
        $category->update([
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
