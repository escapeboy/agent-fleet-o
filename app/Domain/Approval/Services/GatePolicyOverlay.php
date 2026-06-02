<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\DTOs\PolicyVerdict;
use App\Domain\Approval\DTOs\ProposalContext;

/**
 * Lets a versioned AgentPolicy escalate a gate's per-tier blob decision
 * (auto|ask|reject) at the point the operation is attempted — before any
 * proposal exists — so a silent `auto` can be raised to `ask`/`reject` when
 * the team-default policy is stricter. It can only narrow autonomy: the
 * result is the *more cautious* of the blob decision and the policy verdict,
 * never looser. With the flag off / no enabled policy it returns the blob
 * decision unchanged, so gate behavior is identical to before.
 *
 * Gates carry no agent identity, so this consults the team-default policy
 * (agent_id null); agent-specific governance applies on the proposal path
 * via CreateActionProposalAction.
 */
class GatePolicyOverlay
{
    /**
     * Cautiousness ordering — higher wins when combining decisions.
     *
     * @var array<string, int>
     */
    private const RANK = ['auto' => 0, 'ask' => 1, 'reject' => 2];

    public function __construct(
        private readonly AgentPolicyResolver $resolver,
        private readonly PolicyEvaluator $evaluator,
    ) {}

    /**
     * @param  list<string>  $paths
     */
    public function decide(string $teamId, string $targetType, string $risk, array $paths, string $blobDecision): string
    {
        $resolved = $this->resolver->resolve($teamId, null);

        if ($resolved === null) {
            return $blobDecision;
        }

        $verdict = $this->evaluator->evaluate($resolved, new ProposalContext(
            targetType: $targetType,
            riskLevel: $risk,
            paths: $paths,
        ));

        $policyDecision = match ($verdict->decision) {
            PolicyVerdict::DENY => 'reject',
            PolicyVerdict::ALLOW_AUTO => 'auto',
            default => 'ask',
        };

        // $policyDecision is always one of RANK's keys (from the match above);
        // $blobDecision is an arbitrary caller string, so guard only that side.
        return self::RANK[$policyDecision] > (self::RANK[$blobDecision] ?? 0)
            ? $policyDecision
            : $blobDecision;
    }
}
