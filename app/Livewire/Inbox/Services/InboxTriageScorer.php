<?php

declare(strict_types=1);

namespace App\Livewire\Inbox\Services;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Outbound\Models\OutboundProposal;
use Carbon\CarbonInterface;

/**
 * Heuristic risk scorer for inbox items. Pure-PHP, no LLM call.
 *
 * Score in [0.0, 1.0]:
 *   - SLA proximity (past = +0.4, <1h = +0.25, <24h = +0.05)
 *   - Risk score on outbound proposals (linear contribution up to +0.5)
 *   - Item type weight (security review: +0.3, code execution: +0.25, credential: +0.15)
 *   - Age boost (>3d pending: +0.1)
 *
 * Higher score = needs attention sooner. Used to compute the "AI suggests"
 * sort order on InboxPage and surface a recommendation badge.
 */
class InboxTriageScorer
{
    public function scoreApproval(ApprovalRequest $approval): float
    {
        $score = 0.0;

        $score += $this->slaContribution($approval->sla_deadline);

        if ($approval->isSecurityReview()) {
            $score += 0.30;
        } elseif ($this->isCodeExecutionSafe($approval)) {
            $score += 0.25;
        } elseif ($approval->isCredentialReview()) {
            $score += 0.15;
        }

        $score += $this->ageBoost($approval->created_at);

        return $this->clamp($score);
    }

    public function scoreProposal(OutboundProposal $proposal): float
    {
        $score = 0.0;

        // Risk score on the proposal scales linearly into the score.
        $risk = (float) ($proposal->risk_score ?? 0.0);
        $score += min(0.50, max(0.0, $risk * 0.5));

        $score += $this->ageBoost($proposal->created_at);

        return $this->clamp($score);
    }

    public function recommendation(float $score): string
    {
        return match (true) {
            $score >= 0.7 => 'review_now',
            $score >= 0.4 => 'review_soon',
            default => 'low_priority',
        };
    }

    public function recommendationLabel(string $rec): string
    {
        return match ($rec) {
            'review_now' => 'Review now',
            'review_soon' => 'Review soon',
            default => 'Low priority',
        };
    }

    public function recommendationColor(string $rec): string
    {
        return match ($rec) {
            'review_now' => 'red',
            'review_soon' => 'amber',
            default => 'gray',
        };
    }

    /**
     * Like ApprovalRequest::isCodeExecution() but resilient to un-persisted models
     * (the relation query would 500 in unit-test context where the worktree_executions
     * table is not migrated). Falls back to context type when the relation cannot be
     * queried.
     */
    private function isCodeExecutionSafe(ApprovalRequest $approval): bool
    {
        if (! $approval->exists) {
            return ($approval->context['type'] ?? null) === 'code_execution';
        }

        try {
            return $approval->isCodeExecution();
        } catch (\Throwable) {
            return ($approval->context['type'] ?? null) === 'code_execution';
        }
    }

    private function slaContribution(?CarbonInterface $deadline): float
    {
        if ($deadline === null) {
            return 0.0;
        }

        if ($deadline->isPast()) {
            return 0.40;
        }

        $minutesUntil = $deadline->diffInMinutes();
        if ($minutesUntil <= 60) {
            return 0.25;
        }
        if ($minutesUntil <= 1440) {  // 24h
            return 0.05;
        }

        return 0.0;
    }

    private function ageBoost(?CarbonInterface $createdAt): float
    {
        if ($createdAt === null) {
            return 0.0;
        }

        return $createdAt->diffInDays() > 3 ? 0.10 : 0.0;
    }

    private function clamp(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }
}
