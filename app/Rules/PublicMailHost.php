<?php

declare(strict_types=1);

namespace App\Rules;

use App\Contracts\HostResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Anti-SSRF: el servidor del buzon debe ser un nombre o IP PUBLICOS. Se rechazan direcciones privadas, de loopback,
 * de enlace local o reservadas (tambien las que resulten de resolver el nombre) para que un administrador no pueda
 * usar el sistema para sondear la red interna ni servicios locales de la VPS.
 */
final class PublicMailHost implements ValidationRule
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? strtolower(trim($value)) : '';

        if ($host === '' || ! $this->isPlausibleHost($host)) {
            $fail('mailboxes.validation.host_invalid')->translate();

            return;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            $fail('mailboxes.validation.host_unresolvable')->translate();

            return;
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                $fail('mailboxes.validation.host_not_public')->translate();

                return;
            }
        }
    }

    private function isPlausibleHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    /**
     * Rangos que PHP no marca como privados/reservados pero que no son internet publico (CGNAT/Tailscale,
     * documentacion, benchmarking, etc.).
     */
    private const BLOCKED_V4 = [
        '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
    ];

    /**
     * Valida una IP ya resuelta. Tambien la usa el resolvedor de buzones al CONECTAR (el DNS pudo cambiar
     * despues de guardar el buzon).
     */
    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        $isIpv6 = strlen($packed) === 16;
        $embeddedV4 = $isIpv6 && (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")
            || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))
            || str_starts_with($packed, "\x20\x02"));

        // Las IPv6 normales se validan con los filtros de PHP; las que llevan una IPv4 incrustada, por su IPv4.
        if (! $embeddedV4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // IPv6 con IPv4 incrustada (::ffff:a.b.c.d, 64:ff9b::/96, 2002::/16): se revalida la IPv4.
        if (strlen($packed) === 16) {
            $embedded = match (true) {
                str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") => substr($packed, 12, 4),
                str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($packed, 12, 4),
                str_starts_with($packed, "\x20\x02") => substr($packed, 2, 4),
                default => null,
            };

            if ($embedded !== null) {
                return self::isPublic((string) inet_ntop($embedded));
            }

            return true;
        }

        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = (string) inet_pton($network);
        $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return (unpack('N', $packedIp)[1] & $mask) === (unpack('N', $networkPacked)[1] & $mask);
    }
}
