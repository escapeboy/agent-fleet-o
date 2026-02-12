<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use Illuminate\Support\Str;

class CreateAgentAction
{
    public function execute(
        string $name,
        string $provider,
        string $model,
        array $capabilities = [],
        array $config = [],
        ?string $teamId = null,
        ?string $role = null,
        ?string $goal = null,
        ?string $backstory = null,
        array $constraints = [],
        ?int $budgetCapCredits = null,
        array $skillIds = [],
        array $toolIds = [],
    ): Agent {
        $pricing = config("llm_pricing.providers.{$provider}.{$model}");

        $agent = Agent::create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => Str::slug($name),
            'role' => $role,
            'goal' => $goal,
            'backstory' => $backstory,
            'provider' => $provider,
            'model' => $model,
            'status' => AgentStatus::Active,
            'config' => $config,
            'capabilities' => $capabilities,
            'constraints' => $constraints,
            'budget_cap_credits' => $budgetCapCredits,
            'budget_spent_credits' => 0,
            'cost_per_1k_input' => $pricing['input'] ?? 0,
            'cost_per_1k_output' => $pricing['output'] ?? 0,
        ]);

        // Attach skills with priority based on array order
        if (! empty($skillIds)) {
            $syncData = [];
            foreach ($skillIds as $index => $skillId) {
                $syncData[$skillId] = ['priority' => $index];
            }
            $agent->skills()->sync($syncData);
        }

        // Attach tools with priority based on array order
        if (! empty($toolIds)) {
            $syncData = [];
            foreach ($toolIds as $index => $toolId) {
                $syncData[$toolId] = ['priority' => $index];
            }
            $agent->tools()->sync($syncData);
        }

        return $agent;
    }
}
