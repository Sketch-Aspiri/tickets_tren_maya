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
            'coordination_id' => ['required', 'integer', Rule::exists('coordinations', 'id')->where(fn ($query) => $this->user()?->seesAllTeams() ? $query->where('active', true) : $query->where('active', true)->whereIn('id', $this->user()->coordinationIdsQuery()))],
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')->where('coordination_id', $this->input('coordination_id'))],
            'coordinator_id' => ['nullable', 'integer', new ActiveCoordinator],
        ];
    }
}
