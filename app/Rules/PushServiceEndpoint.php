<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El servidor hace un POST al endpoint que registra el navegador; sin lista blanca cualquier usuario
 * podría apuntarlo a un host interno o arbitrario (SSRF). Solo se aceptan los servicios push de los
 * navegadores principales, por HTTPS, sin credenciales ni puertos distintos al 443.
 */
final class PushServiceEndpoint implements ValidationRule
{
    /** @var list<string> */
    public const ALLOWED_HOST_SUFFIXES = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isAllowed($value)) {
            $fail(__('push.errors.invalid_endpoint'));
        }
    }

    public static function isAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
