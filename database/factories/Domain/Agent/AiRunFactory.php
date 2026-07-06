<?php

namespace Database\Factories\Domain\Agent;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRun>
 */
class AiRunFactory extends Factory
{
    protected $model = AiRun::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'agent_id' => null,
            'experiment_id' => null,
            'experiment_stage_id' => null,
            'purpose' => 'test',
            'provider' => 'anthropic',
            'byok_source' => null,
            'model' => 'claude-sonnet-4-5',
            'input_schema' => null,
            'prompt_snapshot' => [
                'system' => fake()->sentence(),
                'user' => fake()->paragraph(),
            ],
            'raw_output' => ['text' => fake()->paragraph()],
            'parsed_output' => null,
            'schema_valid' => true,
            'input_tokens' => fake()->numberBetween(50, 2000),
            'output_tokens' => fake()->numberBetween(50, 2000),
            'cost_credits' => fake()->numberBetween(1, 500),
            'latency_ms' => fake()->numberBetween(100, 5000),
            'status' => 'completed',
            'reasoning_chain' => null,
            'has_reasoning' => false,
        ];
    }
}
