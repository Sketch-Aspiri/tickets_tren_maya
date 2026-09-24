<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;

/**
 * Permite probar sesiones reales: driver `database` y una cookie de sesion por "dispositivo".
 * `actingAs` no sirve aqui porque salta el almacenamiento de sesion.
 */
trait UsesDatabaseSessions
{
    protected function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
    }

    /**
     * Inicia sesion por HTTP como un dispositivo nuevo y devuelve su cookie de sesion (cifrada).
     */
    protected function loginViaHttp(User $user, string $password = UserFactory::DEFAULT_PASSWORD): string
    {
        $this->resetClientState();

        $response = $this->post('/login', ['email' => $user->email, 'password' => $password]);
        $response->assertSessionHasNoErrors();

        return $this->sessionCookieFrom($response);
    }

    /**
     * Las siguientes peticiones se hacen desde el dispositivo dueno de esa cookie.
     */
    protected function asSession(string $cookie): static
    {
        $this->resetClientState();

        return $this->withUnencryptedCookie((string) config('session.cookie'), $cookie);
    }

    protected function sessionCookieFrom(TestResponse $response): string
    {
        $cookie = $response->getCookie((string) config('session.cookie'), false);
        $this->assertNotNull($cookie, 'La respuesta no establecio cookie de sesion.');

        return $cookie->getValue();
    }

    /**
     * La aplicacion de pruebas se reutiliza entre peticiones: se descartan la sesion en memoria
     * y el usuario cacheado del guard para que cada peticion dependa solo de su cookie.
     */
    protected function resetClientState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }
}
