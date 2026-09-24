<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad basicas. HSTS solo sobre HTTPS real fuera de local/testing
 * (detras de un proxy requiere TRUSTED_PROXIES para que isSecure() vea X-Forwarded-Proto).
 *
 * CSP: sin 'unsafe-eval' ni scripts en linea. Alpine se carga con su build compatible con CSP
 * (@alpinejs/csp, ver resources/js/app.js); todo el JavaScript sale de /build.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        if ($this->shouldSendHsts($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Solo sobre una conexion HTTPS real y fuera de local/testing (staging tambien: usa HTTPS).
     */
    private function shouldSendHsts(Request $request): bool
    {
        return $request->isSecure() && ! app()->environment(['local', 'testing']);
    }

    private function contentSecurityPolicy(): string
    {
        $viteDev = $this->viteDevServer();

        $directives = [
            "default-src 'self'",
            "script-src 'self'".$viteDev,
            "style-src 'self' 'unsafe-inline'".$viteDev,
            "img-src 'self' data:",
            "font-src 'self'".$viteDev,
            "connect-src 'self'".$this->viteDevSockets(),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        return implode('; ', $directives);
    }

    /**
     * Solo en desarrollo local con `npm run dev` (existe public/hot).
     */
    private function viteDevServer(): string
    {
        return $this->isViteDevServerRunning() ? ' http://localhost:5173 http://127.0.0.1:5173 http://[::1]:5173' : '';
    }

    private function viteDevSockets(): string
    {
        return $this->isViteDevServerRunning()
            ? ' http://localhost:5173 http://127.0.0.1:5173 ws://localhost:5173 ws://127.0.0.1:5173 ws://[::1]:5173'
            : '';
    }

    private function isViteDevServerRunning(): bool
    {
        return app()->environment('local') && file_exists(public_path('hot'));
    }
}
