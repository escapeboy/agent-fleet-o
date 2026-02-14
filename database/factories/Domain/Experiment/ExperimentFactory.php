<?php

namespace Database\Factories\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExperimentFactory extends Factory
{
    protected $model = Experiment::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'thesis' => fake()->paragraph(),
            'track' => fake()->randomElement(ExperimentTrack::cases()),
            'status' => ExperimentStatus::Draft,
            'constraints' => [],
            'success_criteria' => [],
            'budget_cap_credits' => 5000,
            'budget_spent_credits' => 0,
            'max_iterations' => 3,
            'current_iteration' => 0,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ];
    }

    public function withStatus(ExperimentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function started(): static
    {
        return $this->state([
            'status' => ExperimentStatus::Scoring,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => ExperimentStatus::Completed,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }
}
