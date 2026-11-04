<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStationRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('update', $this->route('station'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('stations', 'name')->ignore($this->route('station'))],
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
