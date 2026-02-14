<?php

namespace Database\Factories\Domain\Agent;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        $name = fake()->name().' Agent';

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'role' => fake()->jobTitle(),
            'goal' => fake()->sentence(),
            'backstory' => fake()->paragraph(),
            'provider' => fake()->randomElement(['anthropic', 'openai', 'google']),
            'model' => fake()->randomElement(['claude-sonnet-4-5-20250929', 'gpt-4o', 'gemini-2.0-flash']),
            'status' => AgentStatus::Active,
            'config' => [],
            'capabilities' => [],
            'constraints' => [],
            'budget_cap_credits' => 10000,
            'budget_spent_credits' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['status' => AgentStatus::Disabled]);
    }
}
