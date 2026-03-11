<?php

namespace Database\Factories\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntityFactory extends Factory
{
    protected $model = Entity::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'team_id' => Team::factory(),
            'type' => fake()->randomElement(['person', 'company', 'location', 'product', 'topic']),
            'name' => $name,
            'canonical_name' => strtolower($name),
            'metadata' => [],
            'mention_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
