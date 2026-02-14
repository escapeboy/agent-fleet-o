<?php

namespace Database\Factories\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        $name = fake()->words(3, true).' Workflow';

        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => WorkflowStatus::Draft,
            'version' => 1,
            'max_loop_iterations' => 10,
            'estimated_cost_credits' => 1000,
            'settings' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => WorkflowStatus::Active]);
    }
}
