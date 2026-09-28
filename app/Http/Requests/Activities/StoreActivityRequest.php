<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use App\Http\Requests\Concerns\ValidatesNewActivityFields;
use App\Http\Requests\Concerns\ValidatesRecurrence;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
{
    use ValidatesNewActivityFields, ValidatesRecurrence;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Activity::class) ?? false;
    }

    /**
     * Los campos comunes de una actividad nueva viven en ValidatesNewActivityFields (compartido con
     * ConvertIncomingEmailRequest); aquí solo se añade la recurrencia, exclusiva de esta ruta.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->newActivityFieldRules(),
            'is_recurring' => ['nullable', 'boolean'],
            ...($this->boolean('is_recurring') ? $this->recurrenceRules() : []),
        ];
    }

    /**
     * @return list<int>
     */
    public function collaboratorIds(): array
    {
        return array_map('intval', (array) $this->validated('collaborator_ids', []));
    }
}
