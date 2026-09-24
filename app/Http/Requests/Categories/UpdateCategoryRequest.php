<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('category')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($this->route('category'))],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * El formulario envia siempre `active` (0/1). Si un cliente lo omite, no se cambia el estado actual.
     *
     * @return array{name: string, active?: bool}
     */
    public function payload(): array
    {
        $payload = ['name' => trim((string) $this->validated('name'))];

        if ($this->has('active')) {
            $payload['active'] = $this->boolean('active');
        }

        return $payload;
    }
}
