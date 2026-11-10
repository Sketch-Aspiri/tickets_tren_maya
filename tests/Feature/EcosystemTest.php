<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Ecosistema Zona Oriente (fase 1, decision 87): selector Tickets | Dashboard sin JavaScript, pie comun,
 * tema compartido por cookie del dominio padre y tipografia servida desde el propio sitio.
 */
class EcosystemTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test']);
    }

    public function test_sidebar_offers_the_system_selector_and_the_shared_footer(): void
    {
        $html = $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('https://dashboard.ejemplo.test/dashboard', false)
            ->assertSee(__('common.ecosystem.footer'))
            ->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]+/dashboard"\s+aria-current="page"#', $html);
        $this->assertStringContainsString('aria-label="'.__('common.ecosystem.label').'"', $html);
    }

    public function test_theme_is_read_first_from_the_shared_cookie(): void
    {
        $early = (string) file_get_contents(public_path('js/tema.js'));
        $toggle = (string) file_get_contents(resource_path('js/theme.js'));

        $this->assertStringContainsString('tema=(sistema|claro|oscuro)', $early);
        $this->assertStringContainsString('SameSite=Lax', $toggle);
        $this->assertStringContainsString('domain=.', $toggle);
    }

    public function test_temayin_explains_how_to_reach_the_dashboard(): void
    {
        $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee(e(__('assistant.faqs.dashboard.q')), false);
    }
}
