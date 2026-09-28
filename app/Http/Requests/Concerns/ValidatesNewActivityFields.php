<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\LocalTime;
use Illuminate\Validation\Rule;

/**
 * Campos de una actividad NUEVA compartidos por StoreActivityRequest y ConvertIncomingEmailRequest
 * (correos entrantes → conversión a Actividad). Deliberadamente NO incluye `is_recurring` ni las reglas
 * de recurrencia: una actividad convertida desde un correo entrante nunca es recurrente, así que
 * `ConvertIncomingEmailRequest` usa este trait solo, sin `ValidatesRecurrence` encima.
 *
 * El equipo es el único del coordinador; quien tiene alcance global o varios equipos debe elegirlo. Que
 * el responsable y los colaboradores sean activos y del equipo correcto lo impone AssignmentService.
 */
trait ValidatesNewActivityFields
{
    use ValidatesWorkItemFields;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function newActivityFieldRules(): array
    {
        return [
            ...$this->workItemFieldRules(),
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => $this->newActivityDueDateRules(),
            'team_id' => $this->newWorkItemTeamRules(),
            'responsible_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'collaborator_ids' => ['nullable', 'array', 'max:'.(int) config('tickets.max_collaborators')],
            'collaborator_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }

    /**
     * La fecha límite de una actividad normal no puede ser anterior a hoy (hora de negocio); una
     * actividad recurrente (solo `StoreActivityRequest`, que añade `is_recurring` encima de este trait)
     * solo debe seguir a su fecha de inicio. `boolean('is_recurring')` resuelve `false` cuando el campo
     * no existe en absoluto en la petición (p. ej. `ConvertIncomingEmailRequest`, que nunca lo expone),
     * así que esa rama siempre exige `after_or_equal:today` — comportamiento sin cambios para
     * `StoreActivityRequest` y el correcto por defecto para cualquier petición nueva que use este trait.
     *
     * @return list<string>
     */
    private function newActivityDueDateRules(): array
    {
        $rules = ['nullable', 'date_format:Y-m-d'];

        if (! $this->boolean('is_recurring')) {
            $rules[] = 'after_or_equal:'.LocalTime::today();
        }

        if ($this->filled('start_date')) {
            $rules[] = 'after_or_equal:start_date';
        }

        return $rules;
    }
}
