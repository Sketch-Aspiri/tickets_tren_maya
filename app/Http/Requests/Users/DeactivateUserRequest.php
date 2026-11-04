<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

class DeactivateUserRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('deactivate', $this->route('user'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
