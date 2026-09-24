<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ResolvesWorkItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asignar / reasignar. Sirve a tickets y a actividades (ruta `{ticket}` o `{activity}`).
 */
class AssignTicketRequest extends FormRequest
{
    use AuthorizesWithGate, ResolvesWorkItem;

    public function authorize(): bool
    {
        return $this->authorizeAbility('assign', $this->workItem());
    }

    /**
     * La existencia se valida aquí; que sean activos y del equipo correcto (según el rol de quien
     * asigna) lo impone AssignmentService, bajo lock del ticket.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'responsible_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'collaborator_ids' => ['nullable', 'array', 'max:'.(int) config('tickets.max_collaborators')],
            'collaborator_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
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
