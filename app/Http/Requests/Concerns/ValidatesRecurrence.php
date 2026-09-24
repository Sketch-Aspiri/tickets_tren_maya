<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\RecurrenceFrequency;
use App\Support\RecurrenceRule;
use Illuminate\Validation\Rule;

/**
 * Reglas del editor de recurrencia (campos `recurrence[...]` y `start_date`). Solo se validan cuando la
 * actividad es (o va a ser) recurrente; si no, el editor se ignora por completo (no entra a `validated()`).
 * Las llaves que no aplican a la frecuencia (días de la semana en mensual, etc.) se descartan al normalizar
 * la regla en RecurrenceRule::fromArray.
 */
trait ValidatesRecurrence
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function recurrenceRules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'recurrence' => ['required', 'array'],
            'recurrence.frequency' => ['required', 'string', Rule::enum(RecurrenceFrequency::class)],
            'recurrence.interval' => ['required', 'integer', 'min:1', 'max:'.RecurrenceRule::MAX_INTERVAL],
            'recurrence.days_of_week' => ['nullable', 'array'],
            'recurrence.days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'recurrence.day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'recurrence.ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
