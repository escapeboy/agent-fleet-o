<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\Models\ActionProposal;

/**
 * Assembles a reproducible "why" record for an ActionProposal (idea C).
 * Because the policy *version* in force is pinned on the proposal, the
 * explanation is faithful even after the policy is later changed or rolled
 * back — the auditor sees the exact rules that produced the decision, the
 * rubric breakdown, the policy verdict, and the lineage that led to it.
 *
 * @phpstan-type ExplainArray array<string, mixed>
 */
class ProposalExplainResolver
{
    /**
     * @return array<string, mixed>
     */
    public function explain(ActionProposal $proposal): array
    {
        /** @var array<string, mixed> $breakdown */
        $breakdown = $proposal->rubric_breakdown ?? [];
        $version = $proposal->agentPolicyVersion;

        return [
            'proposal' => [
                'id' => $proposal->id,
                'summary' => $proposal->summary,
                'target_type' => $proposal->target_type,
                'target_id' => $proposal->target_id,
                'risk_level' => $proposal->risk_level,
                'status' => $proposal->status->value,
                'decided_at' => $proposal->decided_at?->toIso8601String(),
                'decision_reason' => $proposal->decision_reason,
                'actor_agent_id' => $proposal->actor_agent_id,
                'actor_user_id' => $proposal->actor_user_id,
            ],
            'rubric' => [
                'score' => $proposal->rubric_score,
                'breakdown' => array_diff_key($breakdown, ['policy_decision' => true]),
            ],
            'policy' => $version === null ? null : [
                'policy_id' => $version->agent_policy_id,
                'version' => $version->version,
                'version_id' => $version->id,
                'rules' => $version->rules,
                'notes' => $version->notes,
                'rolled_back_from_version_id' => $version->rolled_back_from_version_id,
            ],
            'policy_decision' => $breakdown['policy_decision'] ?? null,
            'lineage' => $proposal->lineage ?? [],
        ];
    }
}
