<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Coordination;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coordination>
 */
class CoordinationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'name' => 'Coordinacion '.fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('??-###')),
            'active' => true,
        ];
    }

    /**
     * Los jefes "universales" de la prueba (UserFactory::jefe) supervisan tambien la coordinacion nueva.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Coordination $coordination): void {
            if (UserFactory::$universalBossIds !== []) {
                $coordination->bosses()->syncWithoutDetaching(UserFactory::$universalBossIds);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
