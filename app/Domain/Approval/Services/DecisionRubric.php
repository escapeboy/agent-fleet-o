<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\DTOs\RubricScore;
use App\Domain\Approval\Models\ActionProposal;

/**
 * Deterministic five-dimension scorer for ActionProposals. Borrowed from
 * CraftBot's proactive-task decision rubric, adapted to FleetQ's existing
 * agent-proposed-action entity. No LLM call — every dimension is derived
 * from columns and payload hints, so scoring is predictable and testable.
 */
class DecisionRubric
{
    public const AUTO_EXECUTE = 'auto_execute';

    public const HUMAN_REVIEW = 'human_review';

    public const AUTO_REJECT = 'auto_reject';

    public function evaluate(ActionProposal $proposal): RubricScore
    {
        $impact = $this->hintScore($proposal->payload['impact'] ?? null);
        $risk = $this->riskScore((string) $proposal->risk_level);
        $cost = $this->costScore($proposal);
        $urgency = $this->hintScore($proposal->payload['urgency'] ?? null);
        $confidence = $this->confidenceScore($proposal);

        $total = $impact + $risk + $cost + $urgency + $confidence;

        return new RubricScore(
            impact: $impact,
            risk: $risk,
            cost: $cost,
            urgency: $urgency,
            confidence: $confidence,
            total: $total,
            recommendation: $this->recommend($total, (string) $proposal->risk_level),
        );
    }

    /**
     * Config-aware routing verdict. `critical` risk is always held for human
     * review; auto-routing only applies when explicitly enabled in config.
     */
    private function recommend(int $total, string $riskLevel): string
    {
        if (strtolower($riskLevel) === 'critical') {
            return self::HUMAN_REVIEW;
        }

        if (config('decision_rubric.auto_execute.enabled', false)
            && $total >= (int) config('decision_rubric.auto_execute.threshold', 18)) {
            return self::AUTO_EXECUTE;
        }

        if (config('decision_rubric.auto_reject.enabled', false)
            && $total <= (int) config('decision_rubric.auto_reject.threshold', 8)) {
            return self::AUTO_REJECT;
        }

        return self::HUMAN_REVIEW;
    }

    private function riskScore(string $riskLevel): int
    {
        $map = (array) config('decision_rubric.risk_scores', []);

        return (int) ($map[strtolower($riskLevel)] ?? 2);
    }

    /**
     * Cost from payload.estimated_credits, bucketed; falls back to the
     * configured default when no estimate is supplied.
     */
    private function costScore(ActionProposal $proposal): int
    {
        $estimate = $proposal->payload['estimated_credits'] ?? null;

        if (! is_numeric($estimate)) {
            return (int) config('decision_rubric.cost_default', 3);
        }

        foreach ((array) config('decision_rubric.cost_buckets', []) as $bucket) {
            if ((float) $estimate <= (float) $bucket[0]) {
                return (int) $bucket[1];
            }
        }

        return 1;
    }

    /**
     * Confidence that the action is wanted: user-initiated proposals score
     * higher than agent-initiated ones, with a penalty for risky actions.
     */
    private function confidenceScore(ActionProposal $proposal): int
    {
        if ($proposal->actor_user_id !== null) {
            $score = 4;
        } elseif ($proposal->actor_agent_id !== null) {
            $score = 2;
        } else {
            $score = 3;
        }

        if (in_array(strtolower((string) $proposal->risk_level), ['high', 'critical'], true)) {
            $score--;
        }

        return max(1, $score);
    }

    /**
     * Impact / Urgency dimensions: honour an explicit 1-5 payload hint,
     * otherwise default to a neutral 3 (these are not deterministically
     * derivable from columns).
     */
    private function hintScore(mixed $hint): int
    {
        if (is_numeric($hint)) {
            return (int) max(1, min(5, (int) $hint));
        }

        return 3;
    }
}
