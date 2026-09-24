<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

/**
 * Base de las pruebas Feature: BD limpia (SQLite en memoria) con roles y permisos sembrados.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Inicia sesion como el usuario dejando el desafio 2FA ya superado en la sesion.
     */
    protected function signIn(User $user): static
    {
        return $this->actingAs($user)->withSession([TwoFactorService::SESSION_KEY => $user->getKey()]);
    }

    /**
     * Codigo TOTP valido para el secreto del usuario.
     */
    protected function validOtp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->two_factor_secret);
    }
}
