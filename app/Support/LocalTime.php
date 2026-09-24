<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * "Hoy" y "año" siempre en la zona horaria de negocio (America/Cancun), aunque todo se guarde en UTC.
 * Única fuente para vencimientos, validación de fechas límite y folios.
 */
final class LocalTime
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->timezone((string) config('app.display_timezone'));
    }

    /**
     * Fecha de hoy (Y-m-d) en la zona horaria de negocio.
     */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    public static function year(): int
    {
        return self::now()->year;
    }
}
