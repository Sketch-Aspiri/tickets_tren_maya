<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Datos demo: solo en local (y pruebas). Staging y produccion nunca reciben cuentas
        // demo con contrasena conocida: el primer jefe se crea con `users:create-jefe`.
        if (app()->environment(DemoDataSeeder::ALLOWED_ENVIRONMENTS)) {
            $this->call(DemoDataSeeder::class);

            return;
        }

        $this->command?->warn('Datos demo omitidos en este entorno. Crea el primer jefe de zona con: php artisan users:create-jefe correo@dominio');
    }
}
