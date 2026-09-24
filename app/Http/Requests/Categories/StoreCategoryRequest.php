<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, active: bool}
     */
    public function payload(): array
    {
        return ['name' => trim((string) $this->validated('name')), 'active' => $this->boolean('active', true)];
    }
}
