<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Modo oscuro: el tema se aplica antes de pintar con un archivo propio (sin scripts en linea, CSP
 * `script-src 'self'`) y el selector de la barra lateral usa solo nombres simples (Alpine CSP).
 */
class ThemeTest extends DatabaseTestCase
{
    public function test_guest_and_authenticated_pages_load_the_theme_script_from_the_same_origin(): void
    {
        $script = '<script src="'.asset('js/tema.js').'"></script>';

        $this->get(route('login'))->assertOk()->assertSee($script, false);

        $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee($script, false);

        $this->assertFileExists(public_path('js/tema.js'));
    }

    public function test_sidebar_offers_system_light_and_dark_with_csp_safe_bindings(): void
    {
        $html = $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('x-data="themeToggle"', false)
            ->assertSee(__('common.theme.system'))
            ->assertSee(__('common.theme.light'))
            ->assertSee(__('common.theme.dark'))
            ->getContent();

        foreach (['setSystem', 'setLight', 'setDark'] as $method) {
            $this->assertStringContainsString('x-on:click="'.$method.'"', $html);
        }
        foreach (['isSystem', 'isLight', 'isDark'] as $state) {
            $this->assertStringContainsString('x-bind:aria-pressed="'.$state.'"', $html);
        }
    }

    public function test_the_early_theme_script_only_reads_the_saved_preference(): void
    {
        $source = (string) file_get_contents(public_path('js/tema.js'));

        $this->assertStringContainsString("localStorage.getItem('tema')", $source);
        $this->assertDoesNotMatchRegularExpression('/\b(eval|Function|innerHTML|document\.write)\b/', $source);
    }
}
