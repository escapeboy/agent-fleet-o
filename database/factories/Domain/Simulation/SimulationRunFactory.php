<?php

namespace Database\Factories\Domain\Simulation;

use App\Domain\Shared\Models\Team;
use App\Domain\Simulation\Enums\SimulationStatus;
use App\Domain\Simulation\Models\SimulationRun;
use App\Domain\Simulation\Models\SimulationSuite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationRun>
 */
class SimulationRunFactory extends Factory
{
    protected $model = SimulationRun::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'suite_id' => SimulationSuite::factory(),
            'status' => SimulationStatus::Pending,
            'aggregate' => null,
            'cost_credits' => 0,
            'created_by' => null,
        ];
    }
}
