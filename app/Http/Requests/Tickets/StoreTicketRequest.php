<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\ValidatesWorkItemFields;
use App\Models\Ticket;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    use ValidatesWorkItemFields;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    /**
     * El ticket puede enviarse a CUALQUIER equipo, no solo al del creador (p. ej. un empleado manda un
     * ticket al equipo de TI). Sin equipo elegido se usa el único propio; quien tiene alcance global o
     * varios equipos debe elegirlo (`User::ticketTeamIdFor`, decisión final en TicketService). La fecha
     * límite no puede ser anterior a hoy (hora de negocio).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->workItemFieldRules(),
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.LocalTime::today()],
            'team_id' => $this->ticketTeamRules(),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function ticketTeamRules(): array
    {
        $user = $this->user();

        return [
            Rule::requiredIf(fn (): bool => $user?->mustChooseTeam() ?? false),
            'nullable',
            'integer',
            Rule::exists('teams', 'id'),
        ];
    }
}
