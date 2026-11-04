<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use App\Http\Requests\Concerns\ValidatesCategoryFields;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    use ValidatesCategoryFields;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $coordinationId = $this->input('coordination_id');

        return [
            'coordination_id' => $this->categoryCoordinationRules(),
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->where(fn ($query) => $query->where('coordination_id', $coordinationId === '' ? null : $coordinationId))],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{coordination_id: ?int, name: string, active: bool}
     */
    public function payload(): array
    {
        return [
            'coordination_id' => $this->categoryCoordinationId(),
            'name' => trim((string) $this->validated('name')),
            'active' => $this->boolean('active', true),
        ];
    }
}
