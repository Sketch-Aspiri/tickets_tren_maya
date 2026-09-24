<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Expresiones SQL que difieren entre SQLite (pruebas) y MySQL 8 (producción) para las consultas agregadas del
 * panel. Regla: los nombres de columna vienen SIEMPRE del código (se validan con una lista estricta de
 * caracteres) y los números son enteros calculados aquí; nunca entra texto del usuario en estas expresiones.
 */
final class SqlExpressions
{
    /**
     * Fecha local (Y-m-d) de un timestamp guardado en la zona de la aplicación (UTC).
     * `$offsetMinutes` = diferencia entre la zona de negocio y la de almacenamiento (Cancún: -300).
     * Se usa un desfase fijo: America/Cancun no tiene horario de verano; con una zona que sí lo tuviera, las
     * ocurrencias cercanas al cambio de hora podrían caer un día corrido (documentado en la decisión 47).
     */
    public static function localDate(string $column, int $offsetMinutes): string
    {
        $column = self::column($column);

        return match (self::driver()) {
            'sqlite' => sprintf("date(%s, '%+d minutes')", $column, $offsetMinutes),
            'mysql', 'mariadb' => sprintf('date(date_add(%s, interval %d minute))', $column, $offsetMinutes),
            default => throw self::unsupported(),
        };
    }

    /**
     * Segundos entre dos timestamps (`$end - $start`), para promediar el tiempo de cierre sin cargar filas.
     */
    public static function secondsBetween(string $start, string $end): string
    {
        $start = self::column($start);
        $end = self::column($end);

        return match (self::driver()) {
            'sqlite' => sprintf("(strftime('%%s', %s) - strftime('%%s', %s))", $end, $start),
            'mysql', 'mariadb' => sprintf('timestampdiff(second, %s, %s)', $start, $end),
            default => throw self::unsupported(),
        };
    }

    /**
     * Desfase en minutos de la zona de negocio respecto a la de almacenamiento en el momento dado.
     */
    public static function displayOffsetMinutes(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $display = $at->timezone((string) config('app.display_timezone'))->utcOffset();
        $storage = $at->timezone((string) config('app.timezone'))->utcOffset();

        return (int) ($display - $storage);
    }

    private static function column(string $column): string
    {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $column) !== 1) {
            throw new InvalidArgumentException('Columna SQL no permitida en una expresión del panel.');
        }

        return $column;
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    private static function unsupported(): RuntimeException
    {
        return new RuntimeException('El panel de seguimiento solo soporta SQLite y MySQL/MariaDB.');
    }
}
