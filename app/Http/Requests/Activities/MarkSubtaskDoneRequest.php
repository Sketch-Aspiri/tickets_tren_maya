<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

class MarkSubtaskDoneRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('markDone', $this->route('subtask'));
    }

    /**
     * El estado deseado viaja explícito (`done` = 1|0): marcar dos veces es idempotente, no alterna.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'done' => ['required', 'boolean'],
        ];
    }

    public function isDone(): bool
    {
        return (bool) $this->validated('done');
    }
}
