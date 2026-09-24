<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('assign', $this->route('ticket'));
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
