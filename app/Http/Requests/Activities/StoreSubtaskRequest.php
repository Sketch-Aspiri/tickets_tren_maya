<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Models\Subtask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubtaskRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('create', [Subtask::class, $this->route('activity')]);
    }

    /**
     * La existencia del responsable se valida aquí; que sea activo y de la actividad/equipo lo impone
     * SubtaskService bajo lock.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function assignee(): ?int
    {
        $assignee = $this->validated('assigned_to');

        return $assignee === null ? null : (int) $assignee;
    }
}
