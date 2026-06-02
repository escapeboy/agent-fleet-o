<?php

namespace Database\Factories\Domain\Agent;

use App\Domain\Agent\Enums\AgentPolicyStatus;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentPolicy>
 */
class AgentPolicyFactory extends Factory
{
    protected $model = AgentPolicy::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'agent_id' => null,
            'name' => fake()->words(2, true).' policy',
            'status' => AgentPolicyStatus::Active,
            'enabled' => false,
            'current_version_id' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn () => ['enabled' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => AgentPolicyStatus::Archived]);
    }
}
