<?php

declare(strict_types=1);

namespace App\Http\Requests\Emails;

use App\Http\Requests\Concerns\ValidatesNewActivityFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Campos de la Actividad creada al convertir un correo entrante. Mismas reglas que
 * `StoreActivityRequest` (vía el trait compartido `ValidatesNewActivityFields`) EXCEPTO el bloque de
 * recurrencia: una actividad convertida desde correo nunca es recurrente, así que aquí no se usa
 * `ValidatesRecurrence` ni se expone `is_recurring`.
 */
class ConvertIncomingEmailRequest extends FormRequest
{
    use ValidatesNewActivityFields;

    public function authorize(): bool
    {
        return $this->user()?->can('convert', $this->route('incomingEmail')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->newActivityFieldRules();
    }

    /**
     * @return list<int>
     */
    public function collaboratorIds(): array
    {
        return array_map('intval', (array) $this->validated('collaborator_ids', []));
    }
}
