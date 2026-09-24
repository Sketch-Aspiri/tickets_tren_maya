<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ResolvesWorkItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Comentar. Sirve a tickets y a actividades (ruta `{ticket}` o `{activity}`).
 */
class StoreCommentRequest extends FormRequest
{
    use AuthorizesWithGate, ResolvesWorkItem;

    public function authorize(): bool
    {
        return $this->authorizeAbility('comment', $this->workItem());
    }

    /**
     * Texto plano: no se interpreta HTML; se escapa siempre al mostrarlo.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('tickets.comment_max_length')],
        ];
    }
}
