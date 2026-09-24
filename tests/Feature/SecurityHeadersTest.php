<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

class SecurityHeadersTest extends DatabaseTestCase
{
    public function test_basic_security_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_content_security_policy_is_restrictive_but_allows_alpine(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringNotContainsString('*', $csp);
    }

    public function test_headers_are_also_sent_on_redirects_and_errors(): void
    {
        $this->get('/dashboard')->assertRedirect('/login')->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_only_sent_over_https_outside_local_and_testing(): void
    {
        config(['app.url' => 'https://tickets.example.test']);
        $this->get('https://tickets.example.test/login')->assertHeaderMissing('Strict-Transport-Security');

        // Fuera de local/testing TrustHosts actua: se pide el host de APP_URL.
        $this->app['env'] = 'production';

        $this->get('https://tickets.example.test/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('http://tickets.example.test/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_vite_dev_server_is_not_allowed_by_default(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('5173', $csp);
    }

    public function test_no_external_font_or_cdn_hosts_are_referenced_by_the_layouts(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        $this->assertStringNotContainsString('cdn.', $html);
    }
}
