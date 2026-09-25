<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;

/**
 * Vista de "Mis pendientes": lo asignado a la propia persona (`mine`, por defecto) o, solo para coordinadores, lo
 * abierto de sus equipos (`team`). Quien no puede usar la vista de equipo recibe siempre `mine`, lo pida como lo pida.
 */
enum PendingScope: string
{
    case Mine = 'mine';
    case Team = 'team';

    public function label(): string
    {
        return __('tickets.pending_scope.'.$this->value);
    }

    /**
     * Solo un coordinador con al menos un equipo puede ver los pendientes de sus equipos (ni el administrador ni el
     * jefe, que ya ven todo en los listados).
     */
    public static function canUseTeam(User $user): bool
    {
        return $user->canAccessApplication()
            && $user->roleEnum() === UserRole::Coordinador
            && $user->teams()->exists();
    }

    /**
     * Unica definicion del alcance efectivo: `Team` solo si se pidio y el usuario puede usarlo.
     */
    public static function resolve(?string $requested, User $user): self
    {
        return self::tryFrom((string) $requested) === self::Team && self::canUseTeam($user) ? self::Team : self::Mine;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
