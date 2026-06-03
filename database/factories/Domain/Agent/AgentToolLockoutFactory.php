<?php

namespace Database\Factories\Domain\Agent;

use App\Domain\Agent\Enums\ToolLockoutMatchMode;
use App\Domain\Agent\Models\AgentToolLockout;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentToolLockout>
 */
class AgentToolLockoutFactory extends Factory
{
    protected $model = AgentToolLockout::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'agent_id' => null,
            'resource' => 'src/auth.php',
            'match_mode' => ToolLockoutMatchMode::Equals,
            'reason' => 'Rejected in review — auth change needs a second pair of eyes.',
            'locked_by' => null,
            'released_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => ['released_at' => now()]);
    }
}
