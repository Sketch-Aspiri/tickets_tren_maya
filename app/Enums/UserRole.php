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
     * Solo el administrador ve todas las estaciones y coordinaciones (alcance global). El jefe de zona supervisa
     * las coordinaciones que se le asignan (`coordination_user`); coordinadores y empleados, sus equipos.
     */
    public function hasGlobalScope(): bool
    {
        return $this === self::Administrador;
    }

    /**
     * Coordinadores y empleados necesitan al menos un equipo; administrador y jefe de zona no (el jefe se liga a
     * coordinaciones, no a equipos).
     */
    public function requiresTeam(): bool
    {
        return match ($this) {
            self::Coordinador, self::Empleado => true,
            self::Administrador, self::JefeZona => false,
        };
    }

    /**
     * Roles que el sistema nunca puede dejar sin al menos una cuenta activa. Solo el administrador: una
     * coordinacion sin jefe de zona activo la cubre el administrador.
     */
    public function mustKeepOneActive(): bool
    {
        return $this === self::Administrador;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
