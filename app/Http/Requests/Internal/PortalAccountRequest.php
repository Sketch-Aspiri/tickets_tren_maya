<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\UserRole;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cuenta aprobada en el portal (decision 99). La autorizacion es la firma del puente (VerifyPortalBridge): aqui
 * solo se exige que la peticion la haya autenticado.
 */
class PortalAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('bridge_system') === 'portal';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'portal_user_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'regex:/^[^A-Z]+$/D'],
            // Hash bcrypt del portal (no la contrasena): la persona entra aqui con la misma contrasena.
            'password_hash' => ['required', 'string', 'regex:/^\$2y\$\d{2}\$[A-Za-z0-9.\/]{53}$/D'],
            'role' => ['required', 'string', Rule::in(array_map(fn (UserRole $r): string => $r->value, UserRole::cases()))],
            'team_ids' => ['nullable', 'array', 'max:20'],
            'team_ids.*' => ['integer', 'distinct', 'exists:teams,id'],
            // Alternativa a team_ids: el subequipo de esa estacion y area (codigo del Dashboard + clave comun).
            'estacion' => ['nullable', 'string', 'max:8', 'required_with:area'],
            'area' => ['nullable', 'string', 'max:32', 'required_with:estacion'],
            'coordination_ids' => ['nullable', 'array', 'max:20'],
            'coordination_ids.*' => ['integer', 'distinct', 'exists:coordinations,id'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $role = UserRole::tryFrom((string) $this->input('role'));

            if ($this->filled('estacion') && $this->filled('area')) {
                $exists = Team::query()->where('station_code', (string) $this->input('estacion'))->where('area', (string) $this->input('area'))->exists();
                if (! $exists) {
                    $validator->errors()->add('estacion', 'subequipo_inexistente');
                }
            } elseif ($role?->requiresTeam() && (array) $this->input('team_ids', []) === []) {
                $validator->errors()->add('team_ids', 'required');
            }

            if ($role === UserRole::JefeZona && (array) $this->input('coordination_ids', []) === []) {
                $validator->errors()->add('coordination_ids', 'required');
            }
        }];
    }
}
