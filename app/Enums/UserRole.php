<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case JefeZona = 'jefe_zona';
    case Coordinador = 'coordinador';
    case Empleado = 'empleado';

    public function label(): string
    {
        return __('enums.user_role.'.$this->value);
    }

    /**
     * El 2FA es obligatorio para jefe de zona y coordinadores; opcional para empleados.
     */
    public function requiresTwoFactor(): bool
    {
        return match ($this) {
            self::JefeZona, self::Coordinador => true,
            self::Empleado => false,
        };
    }

    /**
     * Los coordinadores y empleados pertenecen a un equipo; el jefe de zona ve todo.
     */
    public function requiresTeam(): bool
    {
        return $this !== self::JefeZona;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
