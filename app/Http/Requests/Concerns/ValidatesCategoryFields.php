<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Reglas compartidas por crear y editar categoria. El administrador puede dejar la coordinacion vacia (categoria
 * global); el jefe de zona debe elegir una de las suyas.
 */
trait ValidatesCategoryFields
{
    /**
     * @return list<mixed>
     */
    protected function categoryCoordinationRules(): array
    {
        $user = $this->user();
        $isAdmin = $user?->seesAllTeams() ?? false;

        return [
            $isAdmin ? 'nullable' : 'required',
            'integer',
            Rule::exists('coordinations', 'id')->where(fn ($query) => $isAdmin ? $query : $query->whereIn('id', $user->coordinationIdsQuery())),
        ];
    }

    protected function categoryCoordinationId(): ?int
    {
        $value = $this->validated('coordination_id');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
