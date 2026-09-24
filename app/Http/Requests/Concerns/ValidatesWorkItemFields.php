<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\Priority;
use Illuminate\Validation\Rule;

/**
 * Campos que el usuario escribe en un ticket o una actividad (crear y editar): título, descripción y prioridad.
 */
trait ValidatesWorkItemFields
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function workItemFieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::enum(Priority::class)],
        ];
    }
}
