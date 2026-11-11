<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Una actividad pedida por el Dashboard no se puede crear (decision 92): el evento no tiene equipo configurado
 * (`sin_equipo`) o el equipo no tiene un coordinador activo al que asignarla (`sin_responsable`). No es un error
 * del sistema: se responde 422 y el Dashboard no reintenta.
 */
final class EcosystemActivityException extends RuntimeException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function noTeam(): self
    {
        return new self('sin_equipo');
    }

    public static function noResponsible(): self
    {
        return new self('sin_responsable');
    }

    /** Ya existe una cuenta con el correo de sistema que no es la cuenta de sistema (inactiva y sin rol ni equipos). */
    public static function invalidSystemUser(): self
    {
        return new self('usuario_sistema_invalido');
    }

    /** Tope de actividades nuevas por evento y hora (con el secreto del puente robado, evita el spam). */
    public static function tooMany(): self
    {
        return new self('demasiadas');
    }
}
