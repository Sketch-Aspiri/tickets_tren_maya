<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Proteccion contra inyeccion de formulas (CSV/Excel injection): un texto que empiece por `=`, `+`, `-`, `@`,
 * tabulador o retorno de carro se interpretaria como formula al abrirlo en una hoja de calculo. Se antepone `'`
 * para que se lea como texto. Toda celda de texto que viene de datos de usuario (titulos, nombres...) pasa por aqui.
 */
final class SpreadsheetSanitizer
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        if ($text === '') {
            return $text;
        }

        // Tambien cuando el disparador va tras espacios o saltos de linea iniciales.
        $leading = ltrim($text, " \n");

        if (in_array($text[0], self::TRIGGERS, true) || ($leading !== '' && in_array($leading[0], self::TRIGGERS, true))) {
            return "'".$text;
        }

        return $text;
    }
}
