<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentRuntimeState;
use App\Domain\Shared\Enums\DataClassification;
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
        ?array $personality = null,
        ?string $dataClassification = null,
    ): Agent {
        $pricing = config("llm_pricing.providers.{$provider}.{$model}");

        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $suffix = 1;
        while (Agent::withoutGlobalScopes()->where('team_id', $teamId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        $agent = Agent::create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => $slug,
            'role' => $role,
            'goal' => $goal,
            'backstory' => $backstory,
            'personality' => $personality,
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
            'data_classification' => $dataClassification
                ? DataClassification::from($dataClassification)
                : DataClassification::Internal,
        ]);

        // Seed runtime state (one-per-agent, tracks lifetime stats)
        AgentRuntimeState::create([
            'agent_id' => $agent->id,
            'team_id' => $teamId,
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
