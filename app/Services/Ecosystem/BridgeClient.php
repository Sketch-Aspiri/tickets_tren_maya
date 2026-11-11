<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente del puente hacia el Dashboard (decision 89). Si el Dashboard no responde, tarda o contesta algo
 * inesperado devuelve null: quien lo usa sigue funcionando sin esos datos. Nunca registra secretos.
 */
final class BridgeClient
{
    public function __construct(private readonly BridgeSignature $signature) {}

    public function enabled(): bool
    {
        return $this->signature->outgoingConfigured();
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $path = '/'.ltrim($path, '/');
        ksort($query);
        $url = config('ecosistema.puente.url').$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('ecosistema.puente.timeout', 3))
                ->connectTimeout((int) config('ecosistema.puente.timeout', 3))
                ->retry(2, 200, throw: false)
                ->withHeaders($this->signature->headers('GET', $path, $query))
                ->get($url);
        } catch (Throwable $e) {
            Log::warning('Puente con el Dashboard no disponible.', ['path' => $path, 'error' => $e::class]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            if ($response->status() !== 404) {
                Log::warning('Puente con el Dashboard: respuesta inesperada.', ['path' => $path, 'status' => $response->status()]);
            }

            return null;
        }

        return $response->json();
    }
}
