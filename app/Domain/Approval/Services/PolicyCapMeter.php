<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Windowed usage for policy spend/frequency caps. Counts the agent's (or
 * team's) action proposals within a rolling window. Self-contained and
 * deterministic — derived from the action_proposals table so it is testable
 * with factories and needs no Redis.
 */
class PolicyCapMeter
{
    /**
     * Number of proposals created in the window for this scope.
     */
    public function countInWindow(string $teamId, ?string $agentId, string $window): int
    {
        return $this->scope($teamId, $agentId, $window)->count();
    }

    /**
     * Sum of estimated credits across approved/executed proposals in the
     * window — the spend the policy is trying to bound.
     */
    public function spendInWindow(string $teamId, ?string $agentId, string $window): float
    {
        $proposals = $this->scope($teamId, $agentId, $window)
            ->whereIn('status', [
                ActionProposalStatus::Approved->value,
                ActionProposalStatus::Executed->value,
            ])
            ->get(['payload']);

        return (float) $proposals->sum(
            fn (ActionProposal $p) => (float) ($p->payload['estimated_credits'] ?? 0),
        );
    }

    /**
     * @return Builder<ActionProposal>
     */
    private function scope(string $teamId, ?string $agentId, string $window)
    {
        $query = ActionProposal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $this->windowStart($window));

        if ($agentId !== null) {
            $query->where('actor_agent_id', $agentId);
        }

        return $query;
    }

    private function windowStart(string $window): CarbonInterface
    {
        $now = CarbonImmutable::now();

        return match ($window) {
            'hour' => $now->subHour(),
            'week' => $now->subWeek(),
            'month' => $now->subMonth(),
            default => $now->subDay(),
        };
    }
}
