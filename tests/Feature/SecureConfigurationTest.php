<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\EnvironmentSecurityCheck;
use App\Support\TrustedHosts;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\DatabaseTestCase;

class SecureConfigurationTest extends DatabaseTestCase
{
    /**
     * Carga un archivo de config con variables de entorno controladas (null = variable ausente).
     *
     * @param  array<string, ?string>  $vars
     * @return array<string, mixed>
     */
    private function configWithEnv(string $file, array $vars): array
    {
        $saved = [];

        foreach ($vars as $key => $value) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                continue;
            }

            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        try {
            return require config_path($file);
        } finally {
            foreach ($saved as $key => [$env, $server, $process]) {
                $env === null ? $this->forget($_ENV, $key) : $_ENV[$key] = $env;
                $server === null ? $this->forget($_SERVER, $key) : $_SERVER[$key] = $server;
                $process === false ? putenv($key) : putenv("{$key}={$process}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function forget(array &$bag, string $key): void
    {
        unset($bag[$key]);
    }

    /**
     * @return array<string, ?string>
     */
    private function sessionEnv(string $appEnv): array
    {
        return ['APP_ENV' => $appEnv, 'SESSION_SECURE_COOKIE' => null, 'SESSION_ENCRYPT' => null, 'SESSION_SAME_SITE' => null];
    }

    private function useSecureProductionSettings(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'session.secure' => true, 'app.url' => 'https://tickets.example.test']);
    }

    /**
     * Fuera de local/testing TrustHosts actua: las peticiones deben ir al host de APP_URL.
     */
    private function loginUrl(string $scheme): string
    {
        return $scheme.'://tickets.example.test/login';
    }

    // --- Cookie de sesion por entorno -------------------------------------------

    public function test_session_cookie_is_secure_encrypted_and_lax_by_default_outside_local(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $session = $this->configWithEnv('session.php', $this->sessionEnv($environment));

            $this->assertTrue($session['secure'], $environment);
            $this->assertTrue($session['encrypt'], $environment);
            $this->assertSame('lax', $session['same_site'], $environment);
            $this->assertTrue($session['http_only'], $environment);
        }
    }

    public function test_session_cookie_defaults_do_not_require_https_in_local_and_testing(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $session = $this->configWithEnv('session.php', $this->sessionEnv($environment));

            $this->assertFalse($session['secure'], $environment);
            $this->assertFalse($session['encrypt'], $environment);
        }
    }

    public function test_session_defaults_can_still_be_overridden_explicitly(): void
    {
        $session = $this->configWithEnv('session.php', [...$this->sessionEnv('production'), 'SESSION_SAME_SITE' => 'strict', 'SESSION_ENCRYPT' => 'false']);

        $this->assertSame('strict', $session['same_site']);
        $this->assertFalse($session['encrypt']);
    }

    // --- Fail-fast --------------------------------------------------------------

    public function test_a_secure_production_configuration_passes_the_check(): void
    {
        $this->useSecureProductionSettings();

        (new EnvironmentSecurityCheck($this->app))->assertSecure();

        $this->assertSame([], (new EnvironmentSecurityCheck($this->app))->violations());
    }

    public function test_production_and_staging_refuse_to_boot_with_debug_enabled(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->useSecureProductionSettings();
            $this->app['env'] = $environment;
            config(['app.debug' => true]);

            try {
                (new EnvironmentSecurityCheck($this->app))->assertSecure();
                $this->fail("No fallo en {$environment} con APP_DEBUG=true.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('APP_DEBUG', $exception->getMessage());
            }
        }
    }

    public function test_production_refuses_insecure_cookie_and_non_https_url(): void
    {
        $this->useSecureProductionSettings();
        config(['session.secure' => false, 'app.url' => 'http://tickets.example.test']);

        $violations = (new EnvironmentSecurityCheck($this->app))->violations();

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE', implode(' ', $violations));
        $this->assertStringContainsString('APP_URL', implode(' ', $violations));
    }

    public function test_staging_is_checked_like_production(): void
    {
        $this->useSecureProductionSettings();
        $this->app['env'] = 'staging';
        config(['session.secure' => null]);

        $this->assertNotSame([], (new EnvironmentSecurityCheck($this->app))->violations());
    }

    public function test_local_and_testing_are_never_blocked_by_the_check(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $this->app['env'] = $environment;
            config(['app.debug' => true, 'session.secure' => false, 'app.url' => 'http://localhost:8000']);

            (new EnvironmentSecurityCheck($this->app))->assertSecure();
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_provider_fails_fast_on_boot_in_a_misconfigured_production(): void
    {
        $this->useSecureProductionSettings();
        config(['app.debug' => true]);

        $this->expectException(RuntimeException::class);

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_the_provider_boots_in_a_correct_production_and_forces_https_urls(): void
    {
        $this->useSecureProductionSettings();

        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/dashboard'));
    }

    public function test_urls_are_not_forced_to_https_in_testing(): void
    {
        $this->assertSame('testing', app()->environment());
        (new AppServiceProvider($this->app))->boot();

        URL::forceScheme('http');
        $this->assertStringStartsWith('http://', url('/dashboard'));
    }

    // --- Proxies y hosts de confianza -------------------------------------------

    public function test_trusted_proxies_and_hosts_are_empty_by_default_and_parsed_from_env(): void
    {
        $defaults = $this->configWithEnv('tickets.php', ['TRUSTED_PROXIES' => null, 'TRUSTED_HOSTS' => null]);
        $this->assertSame([], $defaults['http']['trusted_proxies']);
        $this->assertSame([], $defaults['http']['trusted_hosts']);

        $configured = $this->configWithEnv('tickets.php', ['TRUSTED_PROXIES' => '10.0.0.1, 10.0.0.2 ,,', 'TRUSTED_HOSTS' => 'tickets.example.test,other.example.test']);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $configured['http']['trusted_proxies']);
        $this->assertSame(['tickets.example.test', 'other.example.test'], $configured['http']['trusted_hosts']);
    }

    public function test_trusted_hosts_default_to_the_exact_app_url_host(): void
    {
        config(['app.url' => 'https://tickets.example.test', 'tickets.http.trusted_hosts' => []]);

        $patterns = TrustedHosts::patterns();

        $this->assertCount(1, $patterns);
        $this->assertSame(1, preg_match('{'.$patterns[0].'}i', 'tickets.example.test'));
        $this->assertSame(0, preg_match('{'.$patterns[0].'}i', 'evil.example.test'));
        $this->assertSame(0, preg_match('{'.$patterns[0].'}i', 'sub.tickets.example.test'));
        $this->assertSame(0, preg_match('{'.$patterns[0].'}i', 'tickets.example.test.evil.test'));
    }

    public function test_configured_trusted_hosts_are_anchored_and_escaped(): void
    {
        config(['tickets.http.trusted_hosts' => ['tickets.example.test', 'a.b.test']]);

        $patterns = TrustedHosts::patterns();

        $this->assertCount(2, $patterns);
        $this->assertSame(1, preg_match('{'.$patterns[0].'}i', 'tickets.example.test'));
        $this->assertSame(0, preg_match('{'.$patterns[0].'}i', 'ticketsXexample.test'));
    }

    public function test_host_and_session_authentication_middleware_are_registered(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertContains(TrustHosts::class, $kernel->getGlobalMiddleware());
        $this->assertContains('auth.session', $kernel->getMiddlewareGroups()['web']);
    }

    public function test_configured_trusted_proxies_make_forwarded_https_visible_to_the_app(): void
    {
        $this->useSecureProductionSettings();
        config(['tickets.http.trusted_proxies' => ['127.0.0.1']]);
        (new AppServiceProvider($this->app))->boot();

        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->call('GET', $this->loginUrl('http'), server: $server)->assertHeader('Strict-Transport-Security');

        $untrusted = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->call('GET', $this->loginUrl('http'), server: $untrusted)->assertHeaderMissing('Strict-Transport-Security');
    }

    // --- HSTS -------------------------------------------------------------------

    public function test_hsts_is_sent_over_https_in_production_and_staging(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->useSecureProductionSettings();
            $this->app['env'] = $environment;

            $this->get($this->loginUrl('https'))
                ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    public function test_hsts_is_not_sent_over_plain_http_even_in_production(): void
    {
        $this->useSecureProductionSettings();

        $this->get($this->loginUrl('http'))->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_never_sent_in_local_or_testing_even_over_https(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $this->app['env'] = $environment;

            $this->get($this->loginUrl('https'))->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_csp_no_longer_allows_unsafe_eval(): void
    {
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    // --- .env.example -----------------------------------------------------------

    public function test_env_example_documents_the_secure_runtime_keys(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach (['TRUSTED_PROXIES', 'TRUSTED_HOSTS', 'SESSION_SAME_SITE=lax', 'SESSION_SECURE_COOKIE', 'LOG_STACK=daily'] as $key) {
            $this->assertStringContainsString($key, $example);
        }
    }
}
