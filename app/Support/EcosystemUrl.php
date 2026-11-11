<?php

declare(strict_types=1);

namespace App\Support;

/**
 * URL a la que lleva un aviso del ecosistema (decision 91). El aviso lo manda el Dashboard por el puente, pero el
 * destino se vuelve a validar aqui y al abrirlo: solo se aceptan rutas de este sitio o URL de los sistemas del
 * ecosistema (`config('ecosistema.urls')`, mismo origen exacto), y la URL se RECONSTRUYE con caracteres
 * permitidos (nunca se devuelve el texto recibido): sin esquemas raros, usuario, fragmento ni `//`.
 */
final class EcosystemUrl
{
    private const PATH = '#^/[A-Za-z0-9/_.-]{0,200}$#D';

    private const QUERY = '#^[A-Za-z0-9=&_.%-]{0,200}$#D';

    /**
     * La URL normalizada (ruta relativa de este sitio o URL absoluta de un sistema del ecosistema) o null.
     */
    public static function normalize(string $url): ?string
    {
        if ($url === '' || strlen($url) > 300 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (preg_match(self::PATH, $path) !== 1 || str_contains($path, '//')) {
            return null;
        }

        $query = $parts['query'] ?? null;

        if ($query !== null && preg_match(self::QUERY, $query) !== 1) {
            return null;
        }

        $suffix = $path.($query !== null ? '?'.$query : '');

        // Ruta de este sitio.
        if (! isset($parts['scheme']) && ! isset($parts['host'])) {
            return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $suffix : null;
        }

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

        foreach ((array) config('ecosistema.urls') as $base) {
            $base = rtrim((string) $base, '/');

            if ($base !== '' && strtolower($base) === $origin) {
                return $base.$suffix;
            }
        }

        return null;
    }

    /**
     * URL absoluta lista para redirigir a partir de una normalizada.
     */
    public static function absolute(string $normalized): string
    {
        return str_starts_with($normalized, '/') ? url($normalized) : $normalized;
    }
}
