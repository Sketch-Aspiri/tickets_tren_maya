<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Interfaz propia de la aplicación: barra de carga, diálogo de confirmación (en vez de window.confirm) y mensajes de
 * validación en español (en vez de los globos del navegador). El comportamiento JS se verificó con jsdom.
 */
class AppUiTest extends DatabaseTestCase
{
    public function test_authenticated_and_guest_layouts_include_the_app_dialog_and_the_loader(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('id="app-page-loader"', false)
            ->assertSee('x-data="appDialog"', false)
            ->assertSee('id="app-validation-messages"', false);

        $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="app-page-loader"', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee(__('common.dialog.confirm'));
    }

    public function test_confirm_forms_keep_working_through_the_app_dialog(): void
    {
        Team::factory()->create(['name' => 'Equipo sin integrantes']);

        $this->signIn(User::factory()->administrador()->create())->get(route('teams.index'))
            ->assertOk()
            ->assertSee('x-data="confirmSubmit"', false)
            ->assertSee('data-confirm="'.e(__('teams.delete_confirm')).'"', false);
    }

    public function test_validation_texts_are_in_spanish_and_safely_encoded(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        preg_match('/id="app-validation-messages" data-messages="([^"]+)"/', $html, $match);
        $messages = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);

        $this->assertSame(__('common.validation_ui.required'), $messages['required'] ?? null);
        $this->assertSame('Este campo es obligatorio.', $messages['required']);
    }

    public function test_no_native_browser_dialogs_remain_in_the_frontend_sources(): void
    {
        foreach (glob(resource_path('js/*.js')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(window\.)?(confirm|alert|prompt)\(/', (string) file_get_contents($file), basename($file));
        }
    }
}
