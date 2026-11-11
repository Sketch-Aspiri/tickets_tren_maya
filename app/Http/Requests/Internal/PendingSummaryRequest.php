<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `GET /interno/pendientes` del puente con el Dashboard (decision 89). Ya paso por `VerifyBridge` (firma, fecha,
 * nonce e IP); aqui solo se valida el correo por el que se pregunta. No hay usuario de sesion ni modelo que
 * autorizar: el alcance lo pone PendingSummaryService con las consultas de "Mis pendientes".
 */
class PendingSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return is_string($this->attributes->get('bridge_system'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'correo' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => 'invalid'], 422));
    }
}
