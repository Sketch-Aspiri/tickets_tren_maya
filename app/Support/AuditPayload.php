<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Capa de presentación de la bitácora: quita las llaves sensibles y convierte los valores a texto plano
 * ANTES de mostrarlos. `AuditLogger` ya descarta secretos al escribir; esto es la segunda barrera (lista negra
 * defensiva) por si una fila antigua o un evento de un paquete los trae. El resultado siempre se imprime con
 * `{{ }}`. Los nombres de llave se comparan en minúsculas.
 */
final class AuditPayload
{
    /** Cualquier llave que CONTENGA alguno de estos fragmentos se elimina. */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'recovery', 'api_key', 'apikey', 'private_key',
        'authorization', 'cookie', 'credential', 'two_factor', 'session',
    ];

    /** Llaves cortas: solo se eliminan si coinciden exactamente (para no borrar `footprint`, `barcode`...). */
    private const SENSITIVE_EXACT = ['code', 'otp', 'pin', 'key', 'hash', 'signature', 'remember'];

    private const MAX_DEPTH = 4;

    private const MAX_VALUE_LENGTH = 300;

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }

            $clean[$key] = is_array($value) && $depth < self::MAX_DEPTH ? self::redact($value, $depth + 1) : $value;
        }

        return $clean;
    }

    public static function isSensitive(string $key): bool
    {
        $normalized = Str::lower($key);

        if (in_array($normalized, self::SENSITIVE_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valor listo para imprimir (texto plano y acotado). Los arreglos se muestran como JSON legible.
     */
    public static function stringify(mixed $value): string
    {
        $text = match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };

        return Str::limit($text, self::MAX_VALUE_LENGTH);
    }
}
