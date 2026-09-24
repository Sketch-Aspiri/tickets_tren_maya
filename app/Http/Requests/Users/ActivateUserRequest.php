<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class ActivateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activate', $this->route('user')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
