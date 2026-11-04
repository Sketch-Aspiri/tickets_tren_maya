<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ValidatesCategoryFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    use AuthorizesWithGate, ValidatesCategoryFields;

    public function authorize(): bool
    {
        return $this->authorizeAbility('update', $this->route('category'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $coordinationId = $this->input('coordination_id');

        return [
            'coordination_id' => $this->categoryCoordinationRules(),
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')
                ->where(fn ($query) => $query->where('coordination_id', $coordinationId === '' ? null : $coordinationId))
                ->ignore($this->route('category'))],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * El formulario envia siempre `active` (0/1). Si un cliente lo omite, no se cambia el estado actual.
     *
     * @return array{coordination_id: ?int, name: string, active?: bool}
     */
    public function payload(): array
    {
        $payload = [
            'coordination_id' => $this->categoryCoordinationId(),
            'name' => trim((string) $this->validated('name')),
        ];

        if ($this->has('active')) {
            $payload['active'] = $this->boolean('active');
        }

        return $payload;
    }
}
