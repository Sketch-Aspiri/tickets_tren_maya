<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ResolvesWorkItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado. Sirve a tickets y a actividades (ruta `{ticket}` o `{activity}`).
 */
class TransitionTicketRequest extends FormRequest
{
    use AuthorizesWithGate, ResolvesWorkItem;

    /**
     * Primero el alcance (404 si el registro no es visible) y, si el destino es un estado válido, el
     * permiso para esa transición concreta (403).
     */
    public function authorize(): bool
    {
        $item = $this->workItem();
        $this->authorizeAbility('view', $item);

        $target = $this->target();

        return $target === null || $this->authorizeAbility('transition', [$item, $target]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(TicketStatus::class)],
            'comment' => ['nullable', 'string', 'max:'.(int) config('tickets.comment_max_length')],
        ];
    }

    public function target(): ?TicketStatus
    {
        $status = $this->input('status');

        return is_string($status) ? TicketStatus::tryFrom($status) : null;
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) ? $comment : null;
    }
}
