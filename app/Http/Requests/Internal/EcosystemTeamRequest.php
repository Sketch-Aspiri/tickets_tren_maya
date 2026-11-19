<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Equipo creado en el Dashboard: la autenticidad la da la firma del puente (VerifyBridge), no un usuario.
 */
class EcosystemTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clave' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'nombre' => ['required', 'string', 'max:100'],
            'estaciones' => ['present', 'array', 'max:50'],
            'estaciones.*.codigo' => ['required', 'string', 'max:10', 'regex:/^[0-9A-Za-z-]+$/'],
            'estaciones.*.nombre' => ['required', 'string', 'max:100'],
        ];
    }
}
