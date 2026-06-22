<?php

namespace Database\Factories\Domain\Simulation;

use App\Domain\Shared\Models\Team;
use App\Domain\Simulation\Enums\SimulationTargetType;
use App\Domain\Simulation\Models\SimulationSuite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SimulationSuite>
 */
class SimulationSuiteFactory extends Factory
{
    protected $model = SimulationSuite::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(3, true).' Suite',
            'target_type' => SimulationTargetType::Agent,
            'target_id' => (string) Str::uuid(),
            'brief' => fake()->sentence(),
            'criteria' => ['relevance', 'correctness'],
            'persona_count' => 3,
            'max_turns' => 2,
            'pass_threshold' => 6.0,
            'created_by' => null,
        ];
    }
}
