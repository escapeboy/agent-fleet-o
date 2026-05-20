<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inbox\Models\InboxTriageResult;
use App\Domain\Outbound\Models\OutboundProposal;

class TriagePromptBuilder
{
    public function systemPrompt(): string
    {
        return <<<'SYS'
You are an inbox triage agent for a workflow operations team. You output STRICT JSON only — no preamble, no code fences, no commentary.

Output schema (REQUIRED keys):
{"score": number 0.0-1.0, "rec": "review_now"|"review_soon"|"low_priority", "reason": "<= 1 sentence"}

Score rubric:
- 0.7+: needs review now (expired SLA, security-sensitive, high risk)
- 0.4-0.7: review soon (approaching deadline, moderate risk)
- 0.0-0.4: low priority (no time pressure)
SYS;
    }

    public function approvalUserPrompt(ApprovalRequest $approval, float $heuristicScore, string $teamId): string
    {
        $lines = [
            'Inbox item:',
            'Kind: '.($approval->isHumanTask() ? 'human_task' : 'approval'),
            'Title: '.$this->approvalTitle($approval),
            'Subtitle: '.($approval->context['summary'] ?? 'n/a'),
            'Created: '.$approval->created_at?->toIso8601String().' ('.$approval->created_at?->diffForHumans().')',
            'SLA deadline: '.($approval->sla_deadline?->toIso8601String() ?? 'none').' (expired: '.($approval->sla_deadline?->isPast() ? 'yes' : 'no').')',
            'Heuristic score: '.number_format($heuristicScore, 2),
            '',
            'Recent team feedback:',
            $this->feedbackSummary($teamId),
        ];

        return implode("\n", $lines);
    }

    public function proposalUserPrompt(OutboundProposal $proposal, float $heuristicScore, string $teamId): string
    {
        $lines = [
            'Inbox item:',
            'Kind: proposal',
            'Channel: '.$proposal->channel->value,
            'Target: '.json_encode($proposal->target),
            'Risk score: '.number_format((float) ($proposal->risk_score ?? 0.0), 2),
            'Created: '.$proposal->created_at?->toIso8601String().' ('.$proposal->created_at?->diffForHumans().')',
            'Heuristic score: '.number_format($heuristicScore, 2),
            '',
            'Recent team feedback:',
            $this->feedbackSummary($teamId),
        ];

        return implode("\n", $lines);
    }

    private function approvalTitle(ApprovalRequest $a): string
    {
        if ($a->isCredentialReview()) {
            return 'Credential review';
        }
        if ($a->isSecurityReview()) {
            return 'Security review';
        }

        return 'Approval request';
    }

    private function feedbackSummary(string $teamId): string
    {
        $recent = InboxTriageResult::where('team_id', $teamId)
            ->whereNotNull('user_action')
            ->orderByDesc('user_acted_at')
            ->limit(20)
            ->get();

        if ($recent->isEmpty()) {
            return '(no historical feedback yet)';
        }

        $approved = $recent->where('user_action', 'approved')->count();
        $rejected = $recent->where('user_action', 'rejected')->count();

        return sprintf(
            'Last %d triaged items: %d%% approved, %d%% rejected.',
            $recent->count(),
            (int) round($approved / max($recent->count(), 1) * 100),
            (int) round($rejected / max($recent->count(), 1) * 100),
        );
    }
}
