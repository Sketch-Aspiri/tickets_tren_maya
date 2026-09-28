<?php

declare(strict_types=1);

namespace App\Http\Requests\Emails;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Descartar un correo entrante exige un motivo obligatorio (hace de "comentario" de la decisión); la
 * Policy ya exige que el correo siga `PendingReview`.
 */
class DiscardIncomingEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('discard', $this->route('incomingEmail')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
