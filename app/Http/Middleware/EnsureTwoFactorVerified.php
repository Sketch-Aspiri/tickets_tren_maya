<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA obligatorio para jefe de zona y coordinador (fuerza la configuracion) y desafio
 * en cada sesion para cualquier usuario que lo haya activado.
 */
final class EnsureTwoFactorVerified
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            return $this->deny($request, 'two_factor_setup_required', 'two-factor.setup');
        }

        if ($user->hasTwoFactorEnabled() && ! $this->twoFactor->isVerifiedInSession($user)) {
            return $this->deny($request, 'two_factor_challenge_required', 'two-factor.challenge');
        }

        return $next($request);
    }

    private function deny(Request $request, string $code, string $route): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => ['code' => $code, 'message' => __('two_factor.json_denied')],
                'meta' => null,
            ], 403);
        }

        return redirect()->route($route);
    }
}
