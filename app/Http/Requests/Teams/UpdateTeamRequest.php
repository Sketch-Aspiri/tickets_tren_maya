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
            'coordinator_id' => ['nullable', 'integer', new ActiveCoordinator($this->route('team'))],
        ];
    }
}
