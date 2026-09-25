<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UserRegistrationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Crea un administrador (rol superior al jefe de zona; solo se otorga por consola o por otro administrador).
 * La contrasena se pide de forma interactiva u oculta: nunca como argumento (quedaria en el historial del shell).
 */
class CreateAdministradorCommand extends Command
{
    protected $signature = 'users:create-admin {email : Correo del administrador} {--name= : Nombre completo}';

    protected $description = 'Crea un usuario administrador activo (debera configurar 2FA en su primer inicio de sesion)';

    public function handle(UserRegistrationService $registration): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nombre completo'));
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->secret('Contrasena (no se muestra)');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'password' => ['required', 'string', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Idempotente: garantiza que existan roles y permisos antes de asignar el rol.
        $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $registration->createAdministrador($name, $email, $password);

        $this->info("Administrador {$email} creado. Configurara 2FA al iniciar sesion.");

        return self::SUCCESS;
    }
}
