<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

/**
 * Solo local (y pruebas), aunque se invoque directamente con `--class`. Idempotente por correo.
 * Los usuarios con rol jefe/coordinador deberan configurar 2FA en su primer inicio de sesion.
 */
class DemoDataSeeder extends Seeder
{
    /** Entornos donde se permiten cuentas demo con contrasena conocida. */
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    private const TEAM_NAMES = ['Operaciones', 'Mantenimiento', 'Administracion'];

    private const EMPLOYEES_PER_TEAM = 3;

    private int $createdUsers = 0;

    public function run(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->command?->warn('DemoDataSeeder solo corre en local. Crea el primer jefe de zona con: php artisan users:create-jefe correo@dominio');

            return;
        }

        [$password, $isGenerated] = $this->resolvePassword();
        $hash = Hash::make($password);
        $this->createdUsers = 0;

        $this->createUser('Jefe de Zona Demo', 'jefe@demo.test', $hash, UserRole::JefeZona, null);

        foreach (self::TEAM_NAMES as $index => $teamName) {
            $team = Team::query()->firstOrCreate(['name' => $teamName]);
            $slug = Str::slug($teamName);

            $coordinator = $this->createUser("Coordinador {$teamName}", "coordinador.{$slug}@demo.test", $hash, UserRole::Coordinador, $team);
            $team->update(['coordinator_id' => $coordinator->id]);

            foreach (range(1, self::EMPLOYEES_PER_TEAM) as $number) {
                $this->createUser("Empleado {$teamName} {$number}", "empleado{$number}.{$slug}@demo.test", $hash, UserRole::Empleado, $team);
            }

            if ($index === 0) {
                $this->createPending('Registro Pendiente Demo', 'pendiente@demo.test', $hash);
            }
        }

        $this->report($password, $isGenerated);
    }

    /**
     * La contrasena configurada debe cumplir la misma politica que cualquier cuenta real.
     *
     * @return array{0: string, 1: bool} contrasena y si fue generada al azar
     */
    private function resolvePassword(): array
    {
        $configured = config('tickets.demo_password');

        if ($configured === null) {
            return [Str::password(16), true];
        }

        $validator = Validator::make(['password' => (string) $configured], ['password' => ['required', 'string', 'max:255', Password::defaults()]]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('DEMO_USER_PASSWORD no cumple la politica de contrasenas: '.implode(' ', $validator->errors()->all()));
        }

        return [(string) $configured, false];
    }

    /**
     * Solo se imprime una contrasena que realmente se aplico a cuentas creadas en esta ejecucion.
     */
    private function report(string $password, bool $isGenerated): void
    {
        if ($this->createdUsers === 0) {
            $this->command?->info('Las cuentas demo ya existian; no se cambio ninguna contrasena.');

            return;
        }

        $this->command?->info($isGenerated
            ? "Contrasena generada para las {$this->createdUsers} cuentas demo nuevas (@demo.test): {$password}"
            : "Cuentas demo nuevas: {$this->createdUsers} (@demo.test). Contrasena tomada de DEMO_USER_PASSWORD.");
    }

    private function createUser(string $name, string $email, string $hash, UserRole $role, ?Team $team): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill([
            'password' => $hash,
            'status' => UserStatus::Active,
            'team_id' => $role->requiresTeam() ? $team?->id : null,
        ])->save();
        $user->assignRole($role->value);
        $this->createdUsers++;

        return $user;
    }

    private function createPending(string $name, string $email, string $hash): void
    {
        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill(['password' => $hash, 'status' => UserStatus::Pending])->save();
        $this->createdUsers++;
    }
}
