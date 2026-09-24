<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Contrasena usada por defecto en las pruebas.
     */
    public const DEFAULT_PASSWORD = 'Password-12345';

    protected static ?string $password = null;

    /**
     * Por defecto un usuario recien registrado: pendiente, sin rol ni equipo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(self::DEFAULT_PASSWORD),
            'status' => UserStatus::Pending,
            'remember_token' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Pending]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Inactive]);
    }

    public function jefe(): static
    {
        return $this->active()
            ->withTwoFactor()
            ->withRole(UserRole::JefeZona);
    }

    public function coordinador(): static
    {
        return $this->active()
            ->state(fn () => ['team_id' => Team::factory()])
            ->withTwoFactor()
            ->withRole(UserRole::Coordinador);
    }

    public function empleado(): static
    {
        return $this->active()
            ->state(fn () => ['team_id' => Team::factory()])
            ->withRole(UserRole::Empleado);
    }

    /**
     * 2FA ya configurado y confirmado (secreto aleatorio valido).
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => (new Google2FA)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function withRole(UserRole $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            Role::findOrCreate($role->value, 'web');
            $user->assignRole($role->value);
        });
    }
}
