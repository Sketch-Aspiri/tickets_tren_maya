<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class ErrorPagesTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        Route::middleware('web')->group(function (): void {
            foreach ([401, 403, 404, 419, 429, 500, 503] as $code) {
                Route::get("/_test-error/{$code}", fn () => abort($code));
            }
            Route::get('/_test-boom', function (): never {
                throw new \RuntimeException('SECRETO-INTERNO tabla users password=hunter2');
            });
            Route::post('/_test-post-419', fn () => abort(419));
        });
    }

    /**
     * @return array<string, array{0:int, 1:string}>
     */
    public static function codes(): array
    {
        return [
            '401' => [401, 'Necesitas iniciar sesión'],
            '403' => [403, 'Acceso no permitido'],
            '404' => [404, 'Página no encontrada'],
            '419' => [419, 'Tu sesión expiró'],
            '429' => [429, 'Demasiadas peticiones'],
            '500' => [500, 'Algo salió mal'],
            '503' => [503, 'Sistema en mantenimiento'],
        ];
    }

    #[DataProvider('codes')]
    public function test_each_error_code_renders_its_custom_page(int $code, string $title): void
    {
        $response = $this->get("/_test-error/{$code}");

        $response->assertStatus($code);
        $response->assertSee($title);
        $response->assertSee("Error {$code}");
        $response->assertSee('<html', false);
    }

    public function test_unknown_route_uses_the_custom_404_page(): void
    {
        $this->get('/no-existe-esta-ruta')->assertNotFound()->assertSee('Página no encontrada');
    }

    public function test_out_of_scope_resource_is_indistinguishable_from_a_real_404(): void
    {
        $ticket = $this->makeTicket($this->teamB, $this->empB1);

        $outOfScope = $this->signIn($this->empA1)->get("/tickets/{$ticket->id}");
        $missing = $this->signIn($this->empA1)->get('/tickets/99999999');

        $outOfScope->assertNotFound()->assertSee('Página no encontrada');
        $this->assertSame($this->normalize($missing->getContent()), $this->normalize($outOfScope->getContent()));
    }

    public function test_json_requests_still_receive_json_not_html(): void
    {
        $response = $this->getJson('/_test-error/404');

        $response->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->assertStringNotContainsString('<html', $response->getContent());

        $this->getJson('/_test-error/403')->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_unhandled_exception_renders_generic_500_without_details(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/_test-boom');

        $response->assertStatus(500)->assertSee('Algo salió mal');
        $response->assertDontSee('SECRETO-INTERNO')->assertDontSee('hunter2')->assertDontSee('RuntimeException');
        $response->assertDontSee(base_path(), false);
    }

    public function test_403_does_not_print_the_exception_message(): void
    {
        Route::middleware('web')->get('/_test-403-msg', fn () => abort(403, 'Detalle-interno-de-policy'));

        $this->get('/_test-403-msg')->assertForbidden()->assertDontSee('Detalle-interno-de-policy');
    }

    public function test_guest_sees_login_action_and_authenticated_user_sees_dashboard_action(): void
    {
        $guest = $this->get('/no-existe');
        $guest->assertSee('Ir al inicio de sesión')->assertSee(route('login'), false);

        $auth = $this->signIn($this->empA1)->get('/no-existe');
        $auth->assertSee('Ir al inicio')->assertDontSee('Ir al inicio de sesión')->assertSee(route('dashboard'), false);
    }

    public function test_419_offers_a_way_to_sign_in_again(): void
    {
        $this->get('/_test-error/419')->assertSee('Iniciar sesión de nuevo')->assertSee(route('login'), false);
    }

    public function test_500_renders_even_if_the_session_and_database_fail(): void
    {
        // Simula BD caida: la vista no debe consultarla (auth()->check() se protege con try/catch).
        $this->app['auth']->extend('broken', fn () => throw new \RuntimeException('db down'));
        config(['auth.guards.web.driver' => 'broken']);
        $this->app['auth']->forgetGuards();

        $html = View::make('errors.500')->render();

        $this->assertStringContainsString('Algo salió mal', $html);
        $this->assertStringNotContainsString('db down', $html);
    }

    public function test_error_pages_have_no_inline_scripts_handlers_or_raw_output(): void
    {
        foreach ([401, 403, 404, 419, 429, 500, 503] as $code) {
            $html = View::make("errors.{$code}")->render();

            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString(' style="', $html);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
            $this->assertStringNotContainsString('/build/', $html, 'La pagina de error no debe depender de Vite.');
        }

        foreach (glob(resource_path('views/errors/*.blade.php')) as $file) {
            $this->assertStringNotContainsString('{!!', (string) file_get_contents($file), basename($file));
            $this->assertStringNotContainsString('getMessage', (string) file_get_contents($file), basename($file));
        }
    }

    public function test_error_responses_keep_security_headers(): void
    {
        $this->get('/no-existe')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy');
    }

    private function normalize(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/(csrf-token" content|href)="[^"]*"/', '', $html));
    }
}
