<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El coordinador de un equipo debe cumplir Team::canBeCoordinatedBy: usuario activo con rol
 * `coordinador` que pertenece a ese equipo. Un equipo nuevo (sin id) aun no tiene integrantes,
 * por lo que no admite coordinador al crearse: se asigna al editarlo, cuando ya hay miembros.
 */
final class ActiveCoordinator implements ValidationRule
{
    public function __construct(private readonly ?Team $team = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->team === null) {
            $fail('teams.validation.coordinator_requires_existing_team')->translate();

            return;
        }

        $candidate = is_numeric($value)
            ? User::query()->with('roles')->find((int) $value)
            : null;

        if ($candidate === null || ! $this->team->canBeCoordinatedBy($candidate)) {
            $fail('teams.validation.coordinator_invalid')->translate();
        }
    }
}
