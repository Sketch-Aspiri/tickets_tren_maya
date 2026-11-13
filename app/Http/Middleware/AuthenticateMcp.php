<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\McpClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al cliente del servidor MCP (decision 94) con un token Bearer. Sin ningun cliente creado el
 * endpoint no existe (404). La busqueda es por hash SHA-256 del token (indice unico) y las respuestas de error
 * no dicen si el token no existe, esta revocado, la cuenta esta inactiva o la IP no esta permitida.
 *
 * El cliente actua como su cuenta (`user_id`): se deja en la peticion para que las herramientas la usen como
 * actor ante las Policies y el alcance de equipos.
 */
class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! McpClient::query()->whereNull('revoked_at')->exists()) {
            abort(404);
        }

        $token = (string) $request->bearerToken();

        if ($token === '' || strlen($token) > 128) {
            return $this->unauthorized();
        }

        $client = McpClient::query()
            ->with('user')
            ->where('token_hash', McpClient::hashToken($token))
            ->first();

        if ($client === null
            || $client->isRevoked()
            || $client->user === null
            || ! $client->user->canAccessApplication()
            || ! $this->ipAllowed($client, $request)) {
            return $this->unauthorized();
        }

        // Una escritura de `last_used_at` por minuto como maximo: evita una escritura en BD por cada peticion.
        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('mcp_client', $client);
        $request->setUserResolver(fn () => $client->user);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function ipAllowed(McpClient $client, Request $request): bool
    {
        $ips = (array) $client->allowed_ips;

        return $ips === [] || in_array($request->ip(), $ips, true);
    }

    private function unauthorized(): Response
    {
        return response()->json(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
