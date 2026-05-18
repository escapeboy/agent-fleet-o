<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\DecisionRubric;
use App\Domain\Assistant\Models\AssistantConversation;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class CreateActionProposalAction
{
    /**
     * Create a pending action proposal. Optionally captures the last 3
     * messages from an assistant conversation as lineage so the human
     * approver sees the chain that led to the proposal.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $lineage
     */
    public function execute(
        string $teamId,
        string $targetType,
        ?string $targetId,
        string $summary,
        array $payload,
        array $lineage = [],
        ?string $userId = null,
        ?string $agentId = null,
        string $riskLevel = 'high',
        ?CarbonInterface $expiresAt = null,
        ?AssistantConversation $conversation = null,
    ): ActionProposal {
        if ($conversation && empty($lineage)) {
            $lineage = $this->captureLineage($conversation);
        }

        $proposal = ActionProposal::create([
            'team_id' => $teamId,
            'actor_user_id' => $userId,
            'actor_agent_id' => $agentId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => Str::limit($summary, 250),
            'payload' => $payload,
            'lineage' => $lineage,
            'risk_level' => $riskLevel,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);

        return $this->applyDecisionRubric($proposal);
    }

    /**
     * Score the fresh proposal against the decision rubric and route it.
     * Scoring is always recorded; auto-execute / auto-reject only fire when
     * enabled in config (both ship off). Anything not auto-routed stays
     * pending for human review.
     */
    private function applyDecisionRubric(ActionProposal $proposal): ActionProposal
    {
        if (! config('decision_rubric.enabled', true)) {
            return $proposal;
        }

        $rubric = app(DecisionRubric::class);
        $score = $rubric->evaluate($proposal);

        $proposal->update([
            'rubric_score' => $score->total,
            'rubric_breakdown' => $score->toArray(),
        ]);

        if ($score->recommendation === DecisionRubric::AUTO_EXECUTE) {
            $proposal->update([
                'status' => ActionProposalStatus::Approved,
                'decided_at' => now(),
                'decision_reason' => "Auto-approved by decision rubric (score {$score->total}/25).",
            ]);
            ActionProposalApproved::dispatch($proposal->refresh());
        } elseif ($score->recommendation === DecisionRubric::AUTO_REJECT) {
            $proposal->update([
                'status' => ActionProposalStatus::Rejected,
                'decided_at' => now(),
                'decision_reason' => "Auto-rejected by decision rubric (score {$score->total}/25).",
            ]);
        }

        return $proposal->refresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function captureLineage(AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()
            ->latest('created_at')
            ->limit(3)
            ->get(['id', 'role', 'content', 'created_at'])
            ->reverse()
            ->values();

        return $messages->map(fn ($m) => [
            'kind' => 'assistant_message',
            'id' => $m->id,
            'role' => (string) $m->role,
            'snippet' => Str::limit((string) $m->content, 200),
            'at' => optional($m->created_at)->toIso8601String(),
        ])->all();
    }
}
