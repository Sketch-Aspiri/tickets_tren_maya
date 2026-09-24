<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\ValidatesTicketFields;
use App\Models\Ticket;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    use ValidatesTicketFields;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    /**
     * El equipo es el del creador; solo el jefe (sin equipo) debe elegirlo. La fecha límite no puede
     * ser anterior a hoy (hora de negocio).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->ticketFieldRules(),
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.LocalTime::today()],
            'team_id' => [
                Rule::requiredIf(fn (): bool => $this->user()?->team_id === null),
                'nullable',
                'integer',
                Rule::exists('teams', 'id'),
            ],
        ];
    }
}
