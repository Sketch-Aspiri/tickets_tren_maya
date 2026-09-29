<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Rules\AllowedAttachment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Enviar un mensaje (texto plano y/o un adjunto). Autoriza ANTES de validar (404 fuera de alcance).
 */
class StoreChatMessageRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('send', $this->route('conversation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.(int) config('tickets.chat.message_max_length'), 'required_without:attachment'],
            'attachment' => [
                'nullable',
                'file',
                'max:'.(int) config('tickets.attachments.max_kilobytes'),
                new AllowedAttachment,
            ],
        ];
    }
}
