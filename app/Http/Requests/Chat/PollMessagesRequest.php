<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mensajes nuevos de una conversacion (polling): `after` es el id del ultimo mensaje que el cliente ya tiene.
 */
class PollMessagesRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('view', $this->route('conversation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'after' => ['required', 'integer', 'min:0'],
        ];
    }
}
