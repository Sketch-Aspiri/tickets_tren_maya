<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Category;
use App\Models\Team;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La categoría de un ticket/actividad debe ser general (sin coordinación) o de la MISMA coordinación que su equipo:
 * un ticket enviado a otra coordinación no puede llevar una categoría de la de origen. Sin equipo resuelto no se
 * decide aquí (lo rechaza la regla del equipo). `$keepCategoryId` permite conservar la que el registro ya tenía.
 */
final class CategoryFitsTeam implements ValidationRule
{
    public function __construct(
        private readonly ?int $teamId,
        private readonly ?int $keepCategoryId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $this->teamId === null || (int) $value === $this->keepCategoryId) {
            return;
        }

        $coordinationId = Team::query()->whereKey($this->teamId)->value('coordination_id');

        if ($coordinationId === null) {
            return;
        }

        $fits = Category::query()
            ->whereKey((int) $value)
            ->where(fn ($query) => $query->whereNull('coordination_id')->orWhere('coordination_id', $coordinationId))
            ->exists();

        if (! $fits) {
            $fail(__('categories.errors.wrong_coordination'));
        }
    }
}
