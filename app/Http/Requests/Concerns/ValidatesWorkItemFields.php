<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\Priority;
use Illuminate\Validation\Rule;

/**
 * Campos que el usuario escribe en un ticket o una actividad (crear y editar): título, descripción y prioridad.
 */
trait ValidatesWorkItemFields
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function workItemFieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::enum(Priority::class)],
        ];
    }

    /**
     * Equipo de un registro NUEVO. Debe elegirlo quien tiene alcance global o varios equipos (un usuario de
     * varios equipos solo puede elegir uno de los suyos); con un solo equipo se usa ese y el valor enviado se
     * ignora (User::workTeamIdFor es la decisión final en el Service).
     *
     * @return list<mixed>
     */
    protected function newWorkItemTeamRules(): array
    {
        $user = $this->user();
        $ownTeamIds = $user !== null && ! $user->seesAllTeams() ? $user->teamIds() : [];

        return [
            Rule::requiredIf(fn (): bool => $user?->mustChooseTeam() ?? false),
            'nullable',
            'integer',
            count($ownTeamIds) > 1 ? Rule::in($ownTeamIds) : Rule::exists('teams', 'id'),
        ];
    }
}
