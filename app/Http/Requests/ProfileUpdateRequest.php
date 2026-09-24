<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El correo identifica a la persona (ingesta de correos en el Sprint 4), por eso no se edita desde el perfil.
 */
class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateProfile', $this->user()) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255', "regex:/^[\p{L}\p{M}0-9 .,'’-]+$/u"],
        ];
    }
}
