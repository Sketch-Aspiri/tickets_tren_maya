<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\HostResolver;
use App\Contracts\MailboxClient;
use App\Contracts\MailboxResolver;
use App\Enums\PermissionName;
use App\Infrastructure\DnsHostResolver;
use App\Infrastructure\WebklexMailboxClient;
use App\Infrastructure\WebklexMailboxResolver;
use App\Listeners\LogAuthenticationActivity;
use App\Models\IncomingEmail;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\DashboardPolicy;
use App\Policies\IncomingEmailPolicy;
use App\Services\Cloud\CloudFileUploader;
use App\Services\Cloud\MicrosoftGraphClient;
use App\Support\AuditCoordinationResolver;
use App\Support\EnvironmentSecurityCheck;
use App\Support\MorphMap;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailboxClient::class, WebklexMailboxClient::class);
        $this->app->bind(MailboxResolver::class, WebklexMailboxResolver::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(CloudFileUploader::class, MicrosoftGraphClient::class);
    }

    public function boot(): void
    {
        // Antes que nada: en staging/produccion una configuracion insegura impide arrancar.
        (new EnvironmentSecurityCheck($this->app))->assertSecure();

        MorphMap::register();
        // Fuera de produccion, una relacion no cargada de antemano (N+1) lanza excepcion: asi las pruebas y el uso
        // local la detectan. En produccion nunca rompe una pantalla (solo seria mas lenta).
        Model::preventLazyLoading(! $this->app->isProduction());
        $this->registerAuthorization();
        $this->configureTransportSecurity();
        $this->configurePasswordPolicy();
        $this->configureRateLimiters();

        Event::subscribe(LogAuthenticationActivity::class);

        // Cada entrada de la bitacora (trait LogsActivity y AuditLogger) queda ligada a su coordinacion.
        ActivityLogEntry::creating(fn (ActivityLogEntry $entry) => app(AuditCoordinationResolver::class)->assign($entry));
    }

    /**
     * Politicas que no se descubren por convencion de nombre: el panel no tiene modelo asociado y la
     * bitacora es el modelo de Spatie (no vive en App\Models).
     */
    private function registerAuthorization(): void
    {
        Gate::define('view-dashboard', [DashboardPolicy::class, 'view']);
        // Eliminar en bloque lo vencido: quien gestiona tickets Y actividades (jefe, coordinador, administrador).
        Gate::define('purge-overdue', fn (User $user): bool => $user->canAccessApplication()
            && $user->checkPermissionTo(PermissionName::TicketsManage->value)
            && $user->checkPermissionTo(PermissionName::ActivitiesManage->value));
        Gate::policy(ActivityLogEntry::class, AuditLogPolicy::class);
        Gate::policy(IncomingEmail::class, IncomingEmailPolicy::class);
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

        $this->configureTicketRateLimiters();
    }

    /**
     * Limites por usuario autenticado (o IP si no hay sesion) para acciones de escritura y descargas
     * de tickets y actividades.
     */
    private function configureTicketRateLimiters(): void
    {
        $limits = [
            'ticket-create' => ['ticket_create_per_hour', 'perHour'],
            'ticket-write' => ['ticket_write_per_minute', 'perMinute'],
            'ticket-comment' => ['ticket_comment_per_minute', 'perMinute'],
            'ticket-upload' => ['ticket_upload_per_minute', 'perMinute'],
            'attachment-download' => ['attachment_download_per_minute', 'perMinute'],
            'activity-create' => ['activity_create_per_hour', 'perHour'],
            'activity-write' => ['activity_write_per_minute', 'perMinute'],
            'activity-comment' => ['activity_comment_per_minute', 'perMinute'],
            'activity-upload' => ['activity_upload_per_minute', 'perMinute'],
            'dashboard' => ['dashboard_per_minute', 'perMinute'],
            'audit-view' => ['audit_per_minute', 'perMinute'],
            'export' => ['export_per_hour', 'perHour'],
            'email-write' => ['email_write_per_minute', 'perMinute'],
            'chat-send' => ['chat_send_per_minute', 'perMinute'],
            'chat-poll' => ['chat_poll_per_minute', 'perMinute'],
            'chat-search' => ['chat_search_per_minute', 'perMinute'],
            'chat-start' => ['chat_start_per_hour', 'perHour'],
            'avatar-upload' => ['avatar_upload_per_hour', 'perHour'],
            'avatar-view' => ['avatar_view_per_minute', 'perMinute'],
            'notifications-poll' => ['notifications_poll_per_minute', 'perMinute'],
            'notifications-write' => ['notifications_write_per_minute', 'perMinute'],
            'overdue-purge' => ['overdue_purge_per_hour', 'perHour'],
            'push-write' => ['push_write_per_minute', 'perMinute'],
            'mailbox-test' => ['mailbox_test_per_minute', 'perMinute'],
            'ecosystem-sso' => ['ecosystem_sso_per_minute', 'perMinute'],
            'ecosystem-bridge' => ['ecosystem_bridge_per_minute', 'perMinute'],
        ];

        foreach ($limits as $name => [$configKey, $window]) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::{$window}((int) config("tickets.rate_limits.{$configKey}"))
                ->by($name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }

        // Servidor MCP (decision 94): el limite corre ANTES de autenticar, asi que se acota por huella del token
        // (nunca el token) o, sin token, por IP; un cliente no agota el cupo de otro.
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute((int) config('tickets.rate_limits.mcp_per_minute'))
            ->by('mcp|'.(($token = (string) $request->bearerToken()) !== '' ? hash('sha256', $token) : $request->ip())));

        // Por IP y ANTES del anterior: quien prueba tokens inventados no obtiene un cupo nuevo por cada uno.
        RateLimiter::for('mcp-ip', fn (Request $request): Limit => Limit::perMinute((int) config('tickets.rate_limits.mcp_ip_per_minute'))
            ->by('mcp-ip|'.$request->ip()));
    }
}
