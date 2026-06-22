<?php

namespace Database\Factories\Domain\Simulation;

use App\Domain\Shared\Models\Team;
use App\Domain\Simulation\Models\SimulationPersona;
use App\Domain\Simulation\Models\SimulationSuite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationPersona>
 */
class SimulationPersonaFactory extends Factory
{
    protected $model = SimulationPersona::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'suite_id' => SimulationSuite::factory(),
            'name' => fake()->name(),
            'profile' => fake()->sentence(),
            'goal' => fake()->sentence(),
            'adversarial_tags' => [],
            'seed_message' => fake()->sentence(),
        ];
    }
}
