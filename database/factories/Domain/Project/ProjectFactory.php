<?php

namespace Database\Factories\Domain\Project;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'type' => ProjectType::OneShot,
            'status' => ProjectStatus::Draft,
            'goal' => fake()->sentence(),
            'agent_config' => [],
            'budget_config' => ['max_credits' => 10000],
            'notification_config' => [],
            'delivery_config' => [],
            'settings' => [],
            'allowed_tool_ids' => [],
            'allowed_credential_ids' => [],
            'total_runs' => 0,
            'successful_runs' => 0,
            'failed_runs' => 0,
            'total_spend_credits' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status' => ProjectStatus::Active,
            'started_at' => now(),
        ]);
    }

    public function continuous(): static
    {
        return $this->state(['type' => ProjectType::Continuous]);
    }
}
