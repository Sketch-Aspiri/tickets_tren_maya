<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DisableTwoFactorRequest extends FormRequest
{
    protected $errorBag = 'twoFactor';

    /**
     * Solo roles sin 2FA obligatorio (empleados) pueden desactivarlo.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('disableTwoFactor', $this->user()) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
        ];
    }
}
