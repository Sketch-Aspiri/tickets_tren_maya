<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Abrir un chat 1 a 1. Que el destinatario sea activo con rol lo revalida ChatService.
 */
class StartDirectConversationRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('start', Conversation::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
