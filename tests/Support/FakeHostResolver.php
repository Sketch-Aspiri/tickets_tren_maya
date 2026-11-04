<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\HostResolver;

/**
 * Doble de HostResolver (sin DNS real): un mapa nombre => direcciones; lo que no este en el mapa no resuelve.
 */
final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(private array $records = []) {}

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}
