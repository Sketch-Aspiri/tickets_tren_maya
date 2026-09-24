<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ValidatesRecurrence;
use App\Http\Requests\Concerns\ValidatesWorkItemFields;
use App\Models\Activity;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityRequest extends FormRequest
{
    use AuthorizesWithGate, ValidatesRecurrence, ValidatesWorkItemFields;

    public function authorize(): bool
    {
        return $this->authorizeAbility('update', $this->activity());
    }

    /**
     * Al editar se conserva la fecha o categoría que la actividad ya tenía aunque hoy ya no sean válidas
     * (fecha pasada, categoría desactivada). La recurrencia solo se edita en una PLANTILLA (y entonces es
     * obligatoria: sigue siendo una serie); en una actividad normal o una instancia el editor se ignora.
     * El responsable y los colaboradores se cambian aparte (asignación), no aquí.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $activity = $this->activity();

        return [
            ...$this->workItemFieldRules(),
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->orWhere('id', $activity->category_id)),
            ],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => $this->dueDateRules($activity),
            ...($activity->isTemplate() ? $this->recurrenceRules() : []),
        ];
    }

    /**
     * @return list<string>
     */
    private function dueDateRules(Activity $activity): array
    {
        $rules = ['nullable', 'date_format:Y-m-d'];

        if (! $activity->isTemplate() && $this->input('due_date') !== $activity->due_date?->toDateString()) {
            $rules[] = 'after_or_equal:'.LocalTime::today();
        }

        if ($this->filled('start_date')) {
            $rules[] = 'after_or_equal:start_date';
        }

        return $rules;
    }

    /**
     * Datos para el servicio: si la actividad es plantilla, la regla de recurrencia es obligatoria.
     *
     * @return array<string, mixed>
     */
    public function activityData(): array
    {
        return [
            ...$this->validated(),
            'is_recurring' => $this->activity()->isTemplate(),
        ];
    }

    private function activity(): Activity
    {
        /** @var Activity */
        return $this->route('activity');
    }
}
