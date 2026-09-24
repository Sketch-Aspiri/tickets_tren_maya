<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTwoFactor', $this->user()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => preg_replace('/\s+/', '', (string) $this->input('code')) ?: null,
            'recovery_code' => trim((string) $this->input('recovery_code')) ?: null,
        ]);
    }

    /**
     * Codigo de la aplicacion autenticadora o, alternativamente, un codigo de recuperacion.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'digits:6'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:32'],
        ];
    }
}
