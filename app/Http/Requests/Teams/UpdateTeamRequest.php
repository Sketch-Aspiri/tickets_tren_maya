<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Rules\ActiveCoordinator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('update', $this->route('team'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'coordination_id' => ['required', 'integer', Rule::exists('coordinations', 'id')->where(fn ($query) => $query->where(fn ($q) => $q->where('active', true)->orWhere('id', $this->route('team')->coordination_id))->when(! $this->user()?->seesAllTeams(), fn ($q) => $q->whereIn('id', $this->user()->coordinationIdsQuery())))],
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')->where('coordination_id', $this->input('coordination_id'))->ignore($this->route('team'))],
            'coordinator_id' => ['nullable', 'integer', 'required_with:additional_coordinator_ids', new ActiveCoordinator($this->route('team'))],
            // Coordinadores adicionales (decision 96): mismos requisitos que el principal y sin repetirlo.
            'additional_coordinator_ids' => ['nullable', 'array', 'max:'.(int) config('tickets.max_additional_coordinators')],
            'additional_coordinator_ids.*' => ['integer', 'distinct', 'different:coordinator_id', new ActiveCoordinator($this->route('team'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'coordinator_id.required_with' => __('teams.validation.additional_requires_principal'),
            'additional_coordinator_ids.*.different' => __('teams.validation.additional_same_as_principal'),
            'additional_coordinator_ids.max' => __('teams.validation.additional_max', ['max' => (int) config('tickets.max_additional_coordinators')]),
        ];
    }
}
