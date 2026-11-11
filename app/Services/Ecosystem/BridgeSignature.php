<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Firma del puente interno servidor a servidor con el Dashboard (decision 89). Es la misma clase que
 * App\Services\Ecosistema\FirmaPuente del Dashboard, con el mismo formato (no cambiar una sin la otra):
 *
 *   firma = hex(hmac_sha256(secreto, METODO \n /ruta \n consulta_normalizada \n sistema \n fecha \n nonce \n sha256(cuerpo)))
 *
 * `consulta_normalizada` son los parametros ordenados por nombre y codificados RFC 3986, para que el
 * resultado no dependa de como cada cliente HTTP escriba la URL. Encabezados: X-Puente-Sistema,
 * X-Puente-Fecha (unix), X-Puente-Nonce (un solo uso) y X-Puente-Firma.
 *
 * Hay un secreto por direccion: lo que llega se verifica con `secreto_entrada` y lo que sale se firma con
 * `secreto_salida`. Los secretos nunca viajan ni aparecen en errores.
 */
final class BridgeSignature
{
    public const SYSTEM = 'X-Puente-Sistema';

    public const DATE = 'X-Puente-Fecha';

    public const NONCE = 'X-Puente-Nonce';

    public const SIGNATURE = 'X-Puente-Firma';

    public function incomingConfigured(): bool
    {
        return strlen((string) config('ecosistema.puente.secreto_entrada')) >= 32;
    }

    public function outgoingConfigured(): bool
    {
        return strlen((string) config('ecosistema.puente.secreto_salida')) >= 32;
    }

    /**
     * Encabezados para una llamada saliente.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public function headers(string $method, string $path, array $query = [], string $body = ''): array
    {
        $system = (string) config('ecosistema.sistema');
        $date = (string) now()->getTimestamp();
        $nonce = Str::random(40);

        return [
            self::SYSTEM => $system,
            self::DATE => $date,
            self::NONCE => $nonce,
            self::SIGNATURE => $this->compute((string) config('ecosistema.puente.secreto_salida'), $method, $path, $query, $system, $date, $nonce, $body),
        ];
    }

    /**
     * Si la peticion entrante es autentica y nueva devuelve el sistema que llama; si no, null.
     */
    public function verify(Request $request): ?string
    {
        if (! $this->incomingConfigured()) {
            return null;
        }

        $system = (string) $request->header(self::SYSTEM, '');
        $date = (string) $request->header(self::DATE, '');
        $nonce = (string) $request->header(self::NONCE, '');
        $signature = (string) $request->header(self::SIGNATURE, '');

        if ($system === config('ecosistema.sistema')
            || ! array_key_exists($system, (array) config('ecosistema.urls'))
            || preg_match('/^\d{1,12}$/', $date) !== 1
            || preg_match('/^[A-Za-z0-9]{20,64}$/', $nonce) !== 1
            || abs(now()->getTimestamp() - (int) $date) > (int) config('ecosistema.puente.ventana', 300)) {
            return null;
        }

        $expected = $this->compute(
            (string) config('ecosistema.puente.secreto_entrada'),
            $request->getMethod(),
            '/'.ltrim($request->path(), '/'),
            $request->query->all(),
            $system,
            $date,
            $nonce,
            (string) $request->getContent(),
        );

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        // Un solo uso, durante mas tiempo que la ventana aceptada.
        $window = (int) config('ecosistema.puente.ventana', 300);

        return Cache::add('ecosistema:puente:nonce:'.hash('sha256', $system.'|'.$nonce), true, $window * 2 + 60) ? $system : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function compute(string $secret, string $method, string $path, array $query, string $system, string $date, string $nonce, string $body): string
    {
        ksort($query);

        return hash_hmac('sha256', implode("\n", [
            strtoupper($method),
            '/'.ltrim($path, '/'),
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            $system,
            $date,
            $nonce,
            hash('sha256', $body),
        ]), $secret);
    }
}
