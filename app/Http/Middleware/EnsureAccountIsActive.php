<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea a cuentas `pending`/`inactive` (o sin rol) en toda ruta autenticada de la aplicacion.
 * Solo la pantalla de estado de cuenta y el logout quedan fuera de este middleware.
 */
final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->canAccessApplication()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return $this->denied();
        }

        return redirect()->route('account.status');
    }

    private function denied(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'error' => ['code' => 'account_not_active', 'message' => __('account.json_denied')],
            'meta' => null,
        ], 403);
    }
}
