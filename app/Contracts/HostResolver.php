<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Resuelve un nombre de host a sus direcciones IP (v4/v6). Contrato aparte para poder probar la regla anti-SSRF
 * sin red.
 */
interface HostResolver
{
    /**
     * @return list<string> vacio si no resuelve
     */
    public function resolve(string $host): array;
}
