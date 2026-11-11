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
}
