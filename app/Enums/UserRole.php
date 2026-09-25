<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Administrador = 'administrador';
    case JefeZona = 'jefe_zona';
    case Coordinador = 'coordinador';
    case Empleado = 'empleado';

    public function label(): string
    {
        return __('enums.user_role.'.$this->value);
    }

    /**
     * El 2FA es obligatorio para administrador, jefe de zona y coordinadores; opcional para empleados.
     */
    public function requiresTwoFactor(): bool
    {
        return match ($this) {
            self::Administrador, self::JefeZona, self::Coordinador => true,
            self::Empleado => false,
        };
    }

    /**
     * Administrador y jefe de zona ven todos los equipos (alcance global): su pertenencia a equipos es
     * opcional y no limita lo que ven. Coordinadores y empleados necesitan al menos un equipo.
     */
    public function hasGlobalScope(): bool
    {
        return match ($this) {
            self::Administrador, self::JefeZona => true,
            self::Coordinador, self::Empleado => false,
        };
    }

    public function requiresTeam(): bool
    {
        return ! $this->hasGlobalScope();
    }

    /**
     * Roles que el sistema nunca puede dejar sin al menos una cuenta activa (quedarian sin quien apruebe cuentas).
     */
    public function mustKeepOneActive(): bool
    {
        return $this->hasGlobalScope();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
