<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\UserRole;
use Illuminate\Validation\Rule;

/**
 * Reglas compartidas por aprobar y editar usuario: rol obligatorio y equipo
 * obligatorio salvo para el jefe de zona.
 */
trait ValidatesRoleAndTeam
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function roleAndTeamRules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
            'team_id' => [
                Rule::requiredIf(fn (): bool => $this->selectedRole()?->requiresTeam() ?? false),
                'nullable',
                'integer',
                Rule::exists('teams', 'id'),
            ],
        ];
    }

    public function selectedRole(): ?UserRole
    {
        return UserRole::tryFrom((string) $this->input('role'));
    }

    /**
     * Los roles que no pertenecen a un equipo (jefe de zona) nunca conservan un `team_id`
     * enviado por el cliente.
     */
    public function selectedTeamId(): ?int
    {
        if (! ($this->selectedRole()?->requiresTeam() ?? false)) {
            return null;
        }

        $teamId = $this->validated('team_id');

        return $teamId === null ? null : (int) $teamId;
    }
}
