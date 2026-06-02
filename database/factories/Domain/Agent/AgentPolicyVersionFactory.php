<?php

namespace Database\Factories\Domain\Agent;

use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentPolicyVersion>
 */
class AgentPolicyVersionFactory extends Factory
{
    protected $model = AgentPolicyVersion::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'agent_policy_id' => AgentPolicy::factory(),
            'version' => 1,
            'created_by' => null,
            'rules' => [
                'allowed_target_types' => null,
                'denied_target_types' => ['migration'],
                'risk_ceiling' => 'medium',
                'auto_execute' => ['enabled' => false, 'threshold' => 18],
                'spend_cap' => null,
                'frequency_cap' => null,
                'sensitive_paths' => [],
            ],
            'notes' => null,
            'rolled_back_from_version_id' => null,
            'created_at' => now(),
        ];
    }
}
