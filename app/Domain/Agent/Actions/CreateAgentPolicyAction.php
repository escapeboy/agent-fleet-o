<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentPolicyStatus;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use Illuminate\Support\Facades\DB;

/**
 * Create a new policy for a scope (team-default when agentId is null) with
 * its first version. Any existing active policy for the same scope is
 * archived so the resolver only ever sees one active policy per scope.
 */
class CreateAgentPolicyAction
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public function execute(
        string $teamId,
        string $name,
        ?string $agentId = null,
        array $rules = [],
        bool $enabled = false,
        ?string $createdBy = null,
        ?string $notes = null,
    ): AgentPolicy {
        $rules = array_merge(config('agent_policies.default_rules', []), $rules);

        return DB::transaction(function () use ($teamId, $name, $agentId, $rules, $enabled, $createdBy, $notes): AgentPolicy {
            // Archive any current active policy for this exact scope.
            AgentPolicy::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('agent_id', $agentId)
                ->where('status', AgentPolicyStatus::Active)
                ->update(['status' => AgentPolicyStatus::Archived]);

            $policy = AgentPolicy::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'agent_id' => $agentId,
                'name' => $name,
                'status' => AgentPolicyStatus::Active,
                'enabled' => $enabled,
            ]);

            $version = AgentPolicyVersion::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'agent_policy_id' => $policy->id,
                'version' => 1,
                'created_by' => $createdBy,
                'rules' => $rules,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $policy->update(['current_version_id' => $version->id]);

            return $policy->refresh();
        });
    }
}
