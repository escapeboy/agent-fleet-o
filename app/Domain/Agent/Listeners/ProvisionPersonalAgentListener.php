<?php

namespace App\Domain\Agent\Listeners;

use App\Domain\Agent\Enums\AgentScope;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;

class ProvisionPersonalAgentListener
{
    public function handle(object $event): void
    {
        $user = $event->user ?? null;
        if (! $user) {
            return;
        }

        $teamId = $user->current_team_id;
        if (! $teamId) {
            return;
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            return;
        }

        // Only provision if team hasn't disabled auto-provision
        if (! ($team->settings['auto_provision_personal_agent'] ?? true)) {
            return;
        }

        // Skip if already has a personal agent
        $exists = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('owner_user_id', $user->id)
            ->where('scope', AgentScope::Personal->value)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            $defaultProvider = config('llm_pricing.default_provider', 'anthropic');
            $defaultModel = config('llm_pricing.default_model', 'claude-sonnet-4-5');
            $pricing = config("llm_pricing.providers.{$defaultProvider}.{$defaultModel}", []);

            Agent::create([
                'team_id' => $teamId,
                'name' => $user->name."'s Assistant",
                'provider' => $defaultProvider,
                'model' => $defaultModel,
                'status' => AgentStatus::Active,
                'scope' => AgentScope::Personal->value,
                'owner_user_id' => $user->id,
                'budget_spent_credits' => 0,
                'cost_per_1k_input' => $pricing['input'] ?? 0,
                'cost_per_1k_output' => $pricing['output'] ?? 0,
            ]);
        } catch (\Throwable) {
            // Non-critical — don't fail registration
        }
    }
}
