<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTwoFactor', $this->user()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => preg_replace('/\s+/', '', (string) $this->input('code'))]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
