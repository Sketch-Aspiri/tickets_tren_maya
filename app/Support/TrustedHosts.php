<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Patrones de hosts de confianza para el middleware TrustHosts (Host header injection).
 * Sin configuracion explicita solo se confia en el host exacto de APP_URL (sin subdominios).
 */
final class TrustedHosts
{
    /**
     * @return list<string> expresiones regulares (sin delimitadores) ancladas al host completo
     */
    public static function patterns(): array
    {
        $hosts = (array) config('tickets.http.trusted_hosts', []);

        if ($hosts === []) {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $hosts = is_string($appHost) && $appHost !== '' ? [$appHost] : [];
        }

        return array_values(array_map(
            static fn (string $host): string => '^'.preg_quote($host).'$',
            array_filter($hosts, static fn (mixed $host): bool => is_string($host) && $host !== ''),
        ));
    }
}
