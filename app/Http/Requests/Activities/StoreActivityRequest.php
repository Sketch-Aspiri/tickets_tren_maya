<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use App\Http\Requests\Concerns\ValidatesRecurrence;
use App\Http\Requests\Concerns\ValidatesWorkItemFields;
use App\Models\Activity;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityRequest extends FormRequest
{
    use ValidatesRecurrence, ValidatesWorkItemFields;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Activity::class) ?? false;
    }

    /**
     * El equipo es el único del coordinador; quien tiene alcance global o varios equipos debe elegirlo. Que el
     * responsable y los colaboradores sean activos y del equipo correcto lo impone AssignmentService. La fecha límite de una
     * actividad normal no puede ser anterior a hoy (hora de negocio); la de una recurrente solo debe seguir
     * a su fecha de inicio (el inicio puede ser pasado: la serie empieza a generar desde hoy).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->workItemFieldRules(),
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => $this->dueDateRules(),
            'team_id' => $this->newWorkItemTeamRules(),
            'responsible_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'collaborator_ids' => ['nullable', 'array', 'max:'.(int) config('tickets.max_collaborators')],
            'collaborator_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
            'is_recurring' => ['nullable', 'boolean'],
            ...($this->boolean('is_recurring') ? $this->recurrenceRules() : []),
        ];
    }

    /**
     * @return list<string>
     */
    private function dueDateRules(): array
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

    /**
     * @return list<int>
     */
    public function collaboratorIds(): array
    {
        return array_map('intval', (array) $this->validated('collaborator_ids', []));
    }
}
