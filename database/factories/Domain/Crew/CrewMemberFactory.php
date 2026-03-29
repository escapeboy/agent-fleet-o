<?php

namespace Database\Factories\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use Illuminate\Database\Eloquent\Factories\Factory;

class CrewMemberFactory extends Factory
{
    protected $model = CrewMember::class;

    public function definition(): array
    {
        return [
            'crew_id' => Crew::factory(),
            'agent_id' => Agent::factory(),
            'role' => CrewMemberRole::Worker,
            'sort_order' => 0,
            'config' => [],
            'context_scope' => null,
        ];
    }

    public function coordinator(): static
    {
        return $this->state(['role' => CrewMemberRole::Coordinator]);
    }

    public function qa(): static
    {
        return $this->state(['role' => CrewMemberRole::Qa]);
    }

    public function processReviewer(): static
    {
        return $this->state(['role' => CrewMemberRole::ProcessReviewer]);
    }

    public function outputReviewer(): static
    {
        return $this->state(['role' => CrewMemberRole::OutputReviewer]);
    }
}
