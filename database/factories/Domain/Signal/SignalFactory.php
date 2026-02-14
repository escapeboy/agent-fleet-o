<?php

namespace Database\Factories\Domain\Signal;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

class SignalFactory extends Factory
{
    protected $model = Signal::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'experiment_id' => Experiment::factory(),
            'source_type' => fake()->randomElement(['webhook', 'rss', 'manual']),
            'source_identifier' => fake()->url(),
            'payload' => ['message' => fake()->sentence()],
            'score' => fake()->randomFloat(2, 0, 1),
            'scoring_details' => [],
            'content_hash' => fake()->sha256(),
            'tags' => [],
            'received_at' => now(),
        ];
    }
}
