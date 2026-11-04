<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Models\Station;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Station::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('stations', 'name')],
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
