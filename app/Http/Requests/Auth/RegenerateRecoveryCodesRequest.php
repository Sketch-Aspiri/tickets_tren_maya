<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateRecoveryCodesRequest extends FormRequest
{
    protected $errorBag = 'twoFactor';

    public function authorize(): bool
    {
        return $this->user()?->can('manageTwoFactor', $this->user()) ?? false;
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
