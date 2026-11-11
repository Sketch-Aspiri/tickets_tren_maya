<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Parametros opcionales de `GET /tickets/create`: `coordination_id` (preselecciona la coordinacion, decision 81)
 * y `prefill` (id del contexto que dejo el Dashboard al "Reportar una falla", decision 89). Un valor invalido no
 * rompe la pantalla: simplemente se ignora (ver `coordinationId()` y `prefillId()`).
 */
class CreateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', Ticket::class);

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function coordinationId(): ?int
    {
        return $this->integer('coordination_id') ?: null;
    }

    public function prefillId(): ?string
    {
        $id = $this->query('prefill');

        return is_string($id) && preg_match('/^[A-Za-z0-9]{40}$/D', $id) === 1 ? $id : null;
    }
}
