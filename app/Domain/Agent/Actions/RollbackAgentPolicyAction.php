<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Roll a policy back to a prior version by minting a NEW version that copies
 * the target's rules (forward-only history) and re-pointing current_version.
 * The new version records rolled_back_from_version_id so the audit trail
 * shows the rollback as a first-class event — the 30-second undo path for an
 * over-permissive policy change.
 */
class RollbackAgentPolicyAction
{
    public function execute(
        AgentPolicy $policy,
        string $targetVersionId,
        ?string $createdBy = null,
    ): AgentPolicy {
        return DB::transaction(function () use ($policy, $targetVersionId, $createdBy): AgentPolicy {
            $target = AgentPolicyVersion::withoutGlobalScopes()
                ->where('agent_policy_id', $policy->id)
                ->whereKey($targetVersionId)
                ->first();

            if (! $target) {
                throw new InvalidArgumentException(
                    "Version [{$targetVersionId}] does not belong to policy [{$policy->id}].",
                );
            }

            $nextVersion = (int) (AgentPolicyVersion::withoutGlobalScopes()
                ->where('agent_policy_id', $policy->id)
                ->max('version') ?? 0) + 1;

            $version = AgentPolicyVersion::withoutGlobalScopes()->create([
                'team_id' => $policy->team_id,
                'agent_policy_id' => $policy->id,
                'version' => $nextVersion,
                'created_by' => $createdBy,
                'rules' => $target->rules,
                'notes' => "Rolled back to v{$target->version}.",
                'rolled_back_from_version_id' => $target->id,
                'created_at' => now(),
            ]);

            $policy->update(['current_version_id' => $version->id]);

            return $policy->refresh();
        });
    }
}
