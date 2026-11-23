<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\UserRole;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cambios del portal sobre una cuenta ya emparejada (estado y/o rol y contexto). La autorizacion es la firma del
 * puente (VerifyPortalBridge); sin actor humano, igual que el alta.
 */
class PortalAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('bridge_system') === 'portal';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['nullable', 'string', Rule::in(['activo', 'inactivo'])],
            'role' => ['nullable', 'string', Rule::in(array_map(fn (UserRole $r): string => $r->value, UserRole::cases()))],
            'team_ids' => ['nullable', 'array', 'max:20'],
            'team_ids.*' => ['integer', 'distinct', 'exists:teams,id'],
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
            if (! $this->filled('estado') && ! $this->filled('role')) {
                $validator->errors()->add('estado', 'required');

                return;
            }

            $role = UserRole::tryFrom((string) $this->input('role'));

            if ($role === null) {
                return;
            }

            if ($this->filled('estacion') && $this->filled('area')) {
                $exists = Team::query()->where('station_code', (string) $this->input('estacion'))->where('area', (string) $this->input('area'))->exists();
                if (! $exists) {
                    $validator->errors()->add('estacion', 'subequipo_inexistente');
                }
            } elseif ($role->requiresTeam() && (array) $this->input('team_ids', []) === []) {
                $validator->errors()->add('team_ids', 'required');
            }

            if ($role === UserRole::JefeZona && (array) $this->input('coordination_ids', []) === []) {
                $validator->errors()->add('coordination_ids', 'required');
            }
        }];
    }
}
