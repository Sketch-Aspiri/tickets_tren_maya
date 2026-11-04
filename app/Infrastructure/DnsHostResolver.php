<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\HostResolver;

final class DnsHostResolver implements HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = [];

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ipv6[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
