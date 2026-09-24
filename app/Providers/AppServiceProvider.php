<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use App\Support\EnvironmentSecurityCheck;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Antes que nada: en staging/produccion una configuracion insegura impide arrancar.
        (new EnvironmentSecurityCheck($this->app))->assertSecure();

        $this->configureTransportSecurity();
        $this->configurePasswordPolicy();
        $this->configureRateLimiters();

        Event::subscribe(LogAuthenticationActivity::class);
    }

    /**
     * HTTPS fuera de local/testing y proxies de confianza (TRUSTED_PROXIES) para que la app vea
     * el esquema real detras de Nginx. Sin proxies configurados no se confia en ninguna cabecera X-Forwarded-*.
     */
    private function configureTransportSecurity(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            URL::forceScheme('https');
        }

        $proxies = (array) config('tickets.http.trusted_proxies', []);

        if ($proxies !== []) {
            TrustProxies::at($proxies === ['*'] ? '*' : $proxies);
        }
    }

    /**
     * Politica minima de contrasenas (config/tickets.php). `uncompromised` consulta una API externa,
     * por eso es configurable y se desactiva en pruebas.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min((int) config('tickets.password.min_length'))
                ->mixedCase()
                ->numbers();

            return config('tickets.password.check_breached') ? $rule->uncompromised() : $rule;
        });
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute((int) config('tickets.rate_limits.login_per_minute'))->by($email.'|'.$request->ip()),
                Limit::perMinute((int) config('tickets.rate_limits.login_per_ip_per_minute'))->by($request->ip()),
            ];
        });

        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour((int) config('tickets.rate_limits.register_per_hour'))->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute((int) config('tickets.rate_limits.password_reset_per_minute'))->by($request->ip()));

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute((int) config('tickets.rate_limits.two_factor_per_minute'))
            ->by('2fa|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
