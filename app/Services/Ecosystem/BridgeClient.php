<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
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
     * Llamada firmada que devuelve el estado y el cuerpo (a diferencia de `get`, que oculta los errores): la usan las
     * herramientas del Dashboard del MCP (decision 97), que necesitan distinguir «apagado», «no disponible» y «error».
     * Sin reintentos: el nonce de la firma es de un solo uso, y repetir una escritura no es inocuo. Nunca lanza.
     *
     * @param  array<string, mixed>|null  $body  se envia como JSON y la firma cubre exactamente esos bytes
     * @return array{ok: bool, status: int, data: array<string, mixed>|null}
     */
    public function call(string $method, string $path, ?array $body = null, ?int $timeout = null): array
    {
        $fallo = ['ok' => false, 'status' => 0, 'data' => null];

        if (! $this->enabled()) {
            return $fallo;
        }

        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');
        $timeout ??= (int) config('ecosistema.ia.timeout', 30);

        try {
            $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Un cuerpo que no se puede codificar no se manda vacio (el Dashboard lo tomaria por otra cosa).
            return $fallo;
        }

        try {
            $request = Http::acceptJson()
                ->timeout($timeout)
                ->connectTimeout((int) config('ecosistema.puente.timeout', 3))
                ->withoutRedirecting()
                ->withHeaders($this->signature->headers($method, $path, [], $json));

            $response = $json === ''
                ? $request->send($method, config('ecosistema.puente.url').$path)
                : $request->withBody($json, 'application/json')->send($method, config('ecosistema.puente.url').$path);
        } catch (Throwable $e) {
            Log::warning('Puente con el Dashboard no disponible.', ['path' => $path, 'error' => $e::class]);

            return $fallo;
        }

        $data = $response->json();

        return ['ok' => $response->successful() && is_array($data), 'status' => $response->status(), 'data' => is_array($data) ? $data : null];
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
                ->withoutRedirecting()
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
