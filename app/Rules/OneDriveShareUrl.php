<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enlace compartido de OneDrive/SharePoint: HTTPS, sin credenciales ni puerto y de un host de Microsoft. El sistema
 * nunca descarga de esa URL: la convierte en un id de `/shares` de Microsoft Graph (URL fija), así que no hay SSRF.
 */
final class OneDriveShareUrl implements ValidationRule
{
    /** @var list<string> */
    private const EXACT_HOSTS = ['1drv.ms', 'onedrive.live.com'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isAllowed($value)) {
            $fail(__('sheet_sources.errors.invalid_url'));
        }
    }

    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        return in_array($host, self::EXACT_HOSTS, true) || str_ends_with($host, '.sharepoint.com');
    }
}
