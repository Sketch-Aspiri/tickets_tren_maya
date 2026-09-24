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

    /**
     * Inicio (00:00 hora de negocio) del día de calendario `$date` (Y-m-d) más `$addDays`, expresado en la zona
     * de almacenamiento (UTC) y con el formato de las columnas de la BD: sirve para comparar contra
     * `created_at`/`completed_at` con un intervalo semiabierto [inicio, fin).
     */
    public static function storageBoundary(string $date, int $addDays = 0): string
    {
        return CarbonImmutable::parse($date, (string) config('app.display_timezone'))
            ->startOfDay()
            ->addDays($addDays)
            ->timezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }
}
