<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Ecosystem\BridgeSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas `/interno/*` del puente con el Dashboard (decision 89). Sin secreto configurado las rutas
 * no existen (404); con IPs configuradas, solo esas; y siempre firma HMAC valida, fecha dentro de la ventana y
 * nonce sin usar. Las respuestas de error no dicen cual de las condiciones fallo.
 */
class VerifyBridge
{
    public function __construct(private readonly BridgeSignature $signature) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->signature->incomingConfigured()) {
            abort(404);
        }

        $ips = (array) config('ecosistema.puente.ips');

        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $system = $this->signature->verify($request);

        if ($system === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->attributes->set('bridge_system', $system);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
