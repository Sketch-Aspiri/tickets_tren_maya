<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Reglas compartidas por aprobar y editar usuario: rol obligatorio y al menos un equipo para los roles que lo
 * exigen (coordinador y empleado). Un usuario de cualquier rol puede pertenecer a varios equipos; administrador y
 * jefe de zona pueden no tener ninguno. Solo quien tiene `admins.manage` puede elegir el rol de administrador.
 */
trait ValidatesRoleAndTeam
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function roleAndTeamRules(): array
    {
        return [
            'role' => ['required', 'string', $this->assignableRoles()],
            'team_ids' => [
                Rule::requiredIf(fn (): bool => $this->selectedRole()?->requiresTeam() ?? false),
                'nullable',
                'array',
            ],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('teams', 'id')],
        ];
    }

    /**
     * Todos los roles, salvo administrador para quien no puede otorgarlo (el mensaje de validacion es el de un
     * rol invalido: no revela que existe).
     */
    private function assignableRoles(): Enum
    {
        $user = $this->user();
        $canGrantAdmin = $user !== null
            && $user->canAccessApplication()
            && $user->checkPermissionTo(PermissionName::AdminsManage->value);

        $rule = Rule::enum(UserRole::class);

        return $canGrantAdmin ? $rule : $rule->except([UserRole::Administrador]);
    }

    public function selectedRole(): ?UserRole
    {
        return UserRole::tryFrom((string) $this->input('role'));
    }

    /**
     * Equipos validados y sin repetir (vacio si no se envio ninguno).
     *
     * @return list<int>
     */
    public function selectedTeamIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('team_ids', []))));
    }
}
