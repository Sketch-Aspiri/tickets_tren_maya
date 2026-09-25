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

    public function administrador(): static
    {
        return $this->active()
            ->withTwoFactor()
            ->withRole(UserRole::Administrador);
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

    /**
     * Pertenece a estos equipos a la vez (cualquier rol puede estar en varios). Se suma a los que ya tenga.
     */
    public function inTeams(Team|int ...$teams): static
    {
        $ids = array_map(fn (Team|int $team): int => $team instanceof Team ? (int) $team->getKey() : $team, $teams);

        return $this->afterCreating(fn (User $user) => $user->teams()->syncWithoutDetaching($ids));
    }

    /**
     * Atajo de las pruebas: `team_id` (un solo equipo) ya no es una columna de `users`; la fabrica lo convierte en
     * una pertenencia a `team_user`. El equipo por defecto de coordinador()/empleado() es una fabrica de `Team`.
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function (User $user): void {
                if (! array_key_exists('team_id', $user->getAttributes())) {
                    return;
                }

                $teamId = $user->getAttribute('team_id');
                $user->offsetUnset('team_id');
                $user->setRelation('factoryTeamIds', $teamId === null ? [] : [(int) $teamId]);
            })
            ->afterCreating(function (User $user): void {
                if (! $user->relationLoaded('factoryTeamIds')) {
                    return;
                }

                $ids = $user->getRelation('factoryTeamIds');
                $user->unsetRelation('factoryTeamIds');
                $user->teams()->syncWithoutDetaching($ids);
            });
    }
}
