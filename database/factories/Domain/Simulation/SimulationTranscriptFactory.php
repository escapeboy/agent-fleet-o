<?php

namespace Database\Factories\Domain\Simulation;

use App\Domain\Shared\Models\Team;
use App\Domain\Simulation\Models\SimulationPersona;
use App\Domain\Simulation\Models\SimulationRun;
use App\Domain\Simulation\Models\SimulationTranscript;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationTranscript>
 */
class SimulationTranscriptFactory extends Factory
{
    protected $model = SimulationTranscript::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'run_id' => SimulationRun::factory(),
            'persona_id' => SimulationPersona::factory(),
            'turns' => [],
            'scores' => [],
            'verdict' => 'pass',
            'failed_turn_index' => null,
        ];
    }
}
