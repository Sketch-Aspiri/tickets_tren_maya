<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Models\Team;
use App\Rules\ActiveCoordinator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Team::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')],
            'coordinator_id' => ['nullable', 'integer', new ActiveCoordinator],
        ];
    }
}
