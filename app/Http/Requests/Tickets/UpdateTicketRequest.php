<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ValidatesWorkItemFields;
use App\Models\Ticket;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    use AuthorizesWithGate, ValidatesWorkItemFields;

    public function authorize(): bool
    {
        return $this->authorizeAbility('update', $this->ticket());
    }

    /**
     * Al editar se conserva la fecha o categoría que el ticket ya tenía aunque hoy ya no sean válidas
     * (fecha pasada, categoría desactivada); solo lo NUEVO se valida contra las reglas de creación.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $ticket = $this->ticket();
        $currentDate = $ticket->due_date?->toDateString();

        return [
            ...$this->workItemFieldRules(),
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->orWhere('id', $ticket->category_id)),
            ],
            'due_date' => array_values(array_filter([
                'nullable',
                'date_format:Y-m-d',
                $this->input('due_date') === $currentDate ? null : 'after_or_equal:'.LocalTime::today(),
            ])),
        ];
    }

    private function ticket(): Ticket
    {
        /** @var Ticket */
        return $this->route('ticket');
    }
}
