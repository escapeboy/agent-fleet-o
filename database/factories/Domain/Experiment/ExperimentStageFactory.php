<?php

namespace Database\Factories\Domain\Experiment;

use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExperimentStageFactory extends Factory
{
    protected $model = ExperimentStage::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'experiment_id' => Experiment::factory(),
            'stage' => fake()->randomElement(StageType::cases()),
            'iteration' => 1,
            'status' => StageStatus::Pending,
            'input_snapshot' => [],
            'output_snapshot' => [],
            'retry_count' => 0,
            'duration_ms' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => StageStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'duration_ms' => 300000,
        ]);
    }
}
