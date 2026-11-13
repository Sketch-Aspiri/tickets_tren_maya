<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Ecosystem\BridgeSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas `/interno/portal/*` (decision 99): servidor a servidor, firmadas con el secreto propio del
 * portal. Sin secreto configurado no existen (404); con IPs configuradas, solo esas; las respuestas de error no
 * dicen cual condicion fallo.
 */
class VerifyPortalBridge
{
    public function __construct(private readonly BridgeSignature $signature) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->signature->portalConfigured()) {
            abort(404);
        }

        $ips = (array) config('ecosistema.puente.ips');

        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if (! $this->signature->verifyPortal($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->attributes->set('bridge_system', 'portal');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
