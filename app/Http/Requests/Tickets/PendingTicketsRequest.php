<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Enums\PendingScope;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PendingTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Ticket::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'activities_page' => ['nullable', 'integer', 'min:1'],
            'scope' => ['nullable', 'string', Rule::in(PendingScope::values())],
        ];
    }

    /**
     * Vista efectiva: `team` solo para un coordinador con equipo; para cualquier otro usuario se ignora y se
     * muestra siempre lo propio (el parametro nunca ensancha lo que ve).
     */
    public function pendingScope(): PendingScope
    {
        return PendingScope::resolve($this->validated('scope'), $this->user());
    }
}
