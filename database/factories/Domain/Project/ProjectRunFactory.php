<?php

namespace Database\Factories\Domain\Project;

use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectRunFactory extends Factory
{
    protected $model = ProjectRun::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'run_number' => fake()->numberBetween(1, 100),
            'status' => ProjectRunStatus::Pending,
            'trigger' => 'manual',
            'input_data' => [],
            'spend_credits' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => ProjectRunStatus::Completed,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);
    }
}
