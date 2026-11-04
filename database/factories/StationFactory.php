<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Estacion '.fake()->unique()->words(2, true),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
