<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Interpreta la fecha de recepción tal como la escribe un cliente de correo en español
 * («martes 11 de agosto 2026, 1:11 p. m.») o en formatos comunes (ISO, dd/mm/aaaa, número de serie de Excel).
 * El texto es dato no confiable: solo se extraen números y nombres de mes, nunca se evalúa. Devuelve la fecha en
 * UTC (la zona en que se guarda todo) o `null` si no se reconoce.
 */
final class SpanishDateTime
{
    private const MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    public static function parse(string $text): ?CarbonImmutable
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > 100) {
            return null;
        }

        $zone = (string) config('app.display_timezone');

        try {
            if (preg_match('/(\d{1,2})\s+de\s+([[:alpha:]]+)\s+(?:de\s+)?(\d{4})(?:,?\s+(\d{1,2}):(\d{2})\s*(?:([ap])\.?\s*m\.?)?)?/iu', $text, $m)) {
                $month = self::MONTHS[mb_strtolower($m[2])] ?? null;

                if ($month === null) {
                    return null;
                }

                $hour = (int) ($m[4] ?? 0);

                if (isset($m[6]) && $m[6] !== '') {
                    $hour = $hour % 12 + (strtolower($m[6]) === 'p' ? 12 : 0);
                }

                return self::make((int) $m[3], $month, (int) $m[1], $hour, (int) ($m[5] ?? 0), $zone);
            }

            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2}))?$/', $text, $m)) {
                return self::make((int) $m[3], (int) $m[2], (int) $m[1], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), $zone);
            }

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/', $text, $m)) {
                return self::make((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), $zone);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Rechaza fechas y horas imposibles (31 de febrero, 25:70) en lugar de dejar que Carbon las desborde.
     */
    private static function make(int $year, int $month, int $day, int $hour, int $minute, string $zone): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $year < 2000 || $year > 2100) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, 0, $zone)->utc();
    }
}
