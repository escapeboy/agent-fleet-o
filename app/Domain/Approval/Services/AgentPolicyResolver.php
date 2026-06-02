<?php

namespace App\Domain\Approval\Services;

use App\Domain\Agent\Enums\AgentPolicyStatus;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use App\Domain\Approval\DTOs\ResolvedPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the effective, enabled policy for a (team, agent) pair.
 *
 * Precedence: agent-specific active+enabled policy → team-default
 * (agent_id null) active+enabled policy → null. A null result means "no
 * governing policy" and the caller keeps its existing (legacy) behavior, so
 * the whole feature is backward-compatible: with the global flag off, or no
 * enabled policy, nothing changes.
 *
 * Runs outside the TeamScope global scope (gates fire from MCP/console where
 * the scope may be unbound) and filters team_id explicitly, matching the
 * PlanEnforcer convention.
 */
class AgentPolicyResolver
{
    public function resolve(string $teamId, ?string $agentId): ?ResolvedPolicy
    {
        if (! config('agent_policies.enabled', false)) {
            return null;
        }

        $policy = null;

        if ($agentId !== null) {
            $policy = $this->query($teamId)
                ->where('agent_id', $agentId)
                ->first();
        }

        if (! $policy) {
            $policy = $this->query($teamId)
                ->whereNull('agent_id')
                ->first();
        }

        if (! $policy || $policy->current_version_id === null) {
            return null;
        }

        $version = AgentPolicyVersion::withoutGlobalScopes()->find($policy->current_version_id);

        if (! $version) {
            return null;
        }

        return new ResolvedPolicy($policy, $version);
    }

    /**
     * @return Builder<AgentPolicy>
     */
    private function query(string $teamId)
    {
        return AgentPolicy::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', AgentPolicyStatus::Active)
            ->where('enabled', true)
            ->latest('updated_at');
    }
}
