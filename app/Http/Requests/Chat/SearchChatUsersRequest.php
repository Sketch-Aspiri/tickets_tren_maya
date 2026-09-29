<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

class SearchChatUsersRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:60'],
        ];
    }
}
