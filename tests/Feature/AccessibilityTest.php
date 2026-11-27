<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Accesibilidad: las preferencias se aplican antes de pintar con un archivo propio (CSP) y el panel usa solo nombres
 * simples de Alpine.
 */
class AccessibilityTest extends DatabaseTestCase
{
    public function test_guest_and_authenticated_pages_offer_the_panel_and_apply_the_preference_early(): void
    {
        $script = '<script src="'.asset('js/accesibilidad-inicial.js').'?v=1"></script>';

        $this->get(route('login'))->assertOk()
            ->assertSee($script, false)
            ->assertSee('x-data="panelAccesibilidad"', false);

        $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))->assertOk()
            ->assertSee($script, false)
            ->assertSee('x-data="panelAccesibilidad"', false)
            ->assertSee('x-on:click="abrir"', false)
            ->assertSee('x-on:change="cambiar"', false);
    }

    public function test_the_early_script_only_reads_the_saved_preference(): void
    {
        $source = (string) file_get_contents(public_path('js/accesibilidad-inicial.js'));

        $this->assertDoesNotMatchRegularExpression('/\b(eval|Function\(|innerHTML|document\.write)/', $source);
    }

    public function test_each_theme_selector_has_its_own_label(): void
    {
        $html = $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))->getContent();

        preg_match_all('/id="(theme-toggle-[A-Za-z0-9]+)"/', (string) $html, $matches);

        $this->assertCount(2, array_unique($matches[1]));
    }

    public function test_pages_have_a_descriptive_title_and_a_skip_link(): void
    {
        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<title>[^<]+ · [^<]+</title>~u', $html);
        $this->assertStringContainsString('href="#contenido"', $html);
        $this->assertStringContainsString('id="contenido"', $html);
    }

    public function test_server_errors_reach_the_accessibility_script(): void
    {
        $html = (string) $this->from(route('login'))->followingRedirects()
            ->post(route('login'), ['email' => '', 'password' => ''])
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('~data-errores="([A-Za-z0-9+/=]+)"~', $html, $match));
        $errors = json_decode((string) base64_decode($match[1], true), true);

        $this->assertContains('email', array_column((array) $errors, 'campo'));
    }

    public function test_only_a_signed_in_person_can_keep_the_session_alive(): void
    {
        $this->get(route('sesion.viva'))->assertRedirect(route('login'));

        $this->signIn(User::factory()->administrador()->create())->get(route('sesion.viva'))->assertNoContent();
    }
}
