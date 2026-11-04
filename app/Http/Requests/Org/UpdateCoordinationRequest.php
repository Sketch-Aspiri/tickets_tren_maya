<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCoordinationRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('update', $this->route('coordination'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'station_id' => ['required', 'integer', Rule::exists('stations', 'id')],
            'name' => ['required', 'string', 'max:255', Rule::unique('coordinations', 'name')->where('station_id', $this->input('station_id'))->ignore($this->route('coordination'))],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coordinations', 'code')->ignore($this->route('coordination'))],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{station_id: int, name: string, code: string, active: bool}
     */
    public function payload(): array
    {
        return [
            'station_id' => (int) $this->validated('station_id'),
            'name' => trim((string) $this->validated('name')),
            'code' => strtoupper((string) $this->validated('code')),
            'active' => $this->boolean('active', true),
        ];
    }
}
