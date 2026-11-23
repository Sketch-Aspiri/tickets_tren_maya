<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Models\User;
use Tests\DatabaseTestCase;

/** El logo de la barra abre el panel «Mis sistemas» del portal (/inicio), no su portada pública. */
class PortalLogoLinkTest extends DatabaseTestCase
{
    public function test_el_logo_lleva_al_panel_del_portal_y_no_a_su_portada(): void
    {
        $this->signIn(User::factory()->jefe()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="https://zonaorientetm.tech/inicio"', false)
            ->assertDontSee('href="https://zonaorientetm.tech"', false);
    }

    public function test_la_direccion_sale_de_la_configuracion_del_portal(): void
    {
        $this->assertSame('https://zonaorientetm.tech/inicio', config('ecosistema.portal_panel'));

        config(['ecosistema.portal_panel' => 'https://portal.ejemplo.test/inicio']);
        $this->signIn(User::factory()->jefe()->create());

        $this->get(route('dashboard'))->assertSee('href="https://portal.ejemplo.test/inicio"', false);
    }

    public function test_la_direccion_base_del_portal_no_cambia_para_otros_enlaces(): void
    {
        $this->assertSame('https://zonaorientetm.tech', config('ecosistema.portal_inicio'));
    }
}
