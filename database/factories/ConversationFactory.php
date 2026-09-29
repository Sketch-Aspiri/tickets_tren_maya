<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['type' => ConversationType::Direct];
    }

    public function team(?Team $team = null): static
    {
        return $this->state(fn (): array => [
            'type' => ConversationType::Team,
            'team_id' => $team?->getKey() ?? Team::factory(),
        ]);
    }
}
