<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyBridge;
use App\Http\Middleware\VerifyPortalBridge;
use App\Support\TrustedHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Puente interno con el Dashboard (decision 89): fuera del grupo `web` (sin sesion ni CSRF).
        then: function (): void {
            Route::middleware(['throttle:ecosystem-bridge', VerifyBridge::class])
                ->prefix('interno')
                ->name('interno.')
                ->group(base_path('routes/internal.php'));

            // Puente con el portal (decision 99): cuentas aprobadas alla y catalogo de equipos. Secreto propio.
            Route::middleware(['throttle:ecosystem-bridge', VerifyPortalBridge::class])
                ->prefix('interno/portal')
                ->name('interno.portal.')
                ->group(base_path('routes/internal_portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'two-factor' => EnsureTwoFactorVerified::class,
            'no-store' => NoStore::class,
        ]);

        // Invalida las sesiones cuyo hash de contrasena ya no coincide (cambio/restablecimiento).
        $middleware->authenticateSessions();

        // Host header: solo el dominio de APP_URL (o TRUSTED_HOSTS). El middleware no actua en
        // local ni en pruebas. Los proxies de confianza (TRUSTED_PROXIES) se fijan en AppServiceProvider.
        $middleware->trustHosts(at: fn (): array => TrustedHosts::patterns(), subdomains: false);

        // Global (no solo grupo web): tambien cubre 404, redirecciones y errores.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Violaciones de reglas de negocio: se muestran al usuario, no son errores del sistema.
        $exceptions->dontReport(BusinessRuleException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson(),
        );
    })->create();
