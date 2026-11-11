<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parametros de las rutas de sesion unica del ecosistema (decision 88). `para` solo puede ser otro
 * sistema conocido (nunca una URL: las direcciones salen de config/ecosistema.php) y `codigo` es texto
 * acotado que despues se verifica con su firma. No hay modelo que autorizar: todo lo decide la firma.
 */
class EcosystemSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $others = array_values(array_diff(array_keys((array) config('ecosistema.urls')), [config('ecosistema.sistema')]));

        return [
            'para' => [Rule::requiredIf($this->routeIs('ecosistema.sso')), 'nullable', 'string', Rule::in($others)],
            'codigo' => ['nullable', 'string', 'max:2048'],
            'estado' => ['nullable', 'string', 'alpha_num', 'size:40'],
            'sin_sesion' => ['nullable'],
        ];
    }
}
