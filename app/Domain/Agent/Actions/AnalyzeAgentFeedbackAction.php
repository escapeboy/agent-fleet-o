<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentFeedback;
use App\Domain\Evolution\Actions\AnalyzeExecutionForEvolutionAction;
use App\Domain\Evolution\Models\EvolutionProposal;
use Illuminate\Support\Collection;

class AnalyzeAgentFeedbackAction
{
    public function __construct(
        private readonly AnalyzeExecutionForEvolutionAction $analyzeEvolution,
    ) {}

    /**
     * Analyze accumulated negative feedback for an agent and generate an EvolutionProposal.
     * Returns null if there is insufficient feedback to warrant an analysis.
     */
    public function execute(Agent $agent): ?EvolutionProposal
    {
        $negativeFeedback = AgentFeedback::where('agent_id', $agent->id)
            ->where('team_id', $agent->team_id)
            ->where('score', '<=', FeedbackRating::Neutral->value)
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->limit(50)
            ->get();

        if ($negativeFeedback->count() < 3) {
            return null;
        }

        // Build a feedback summary to inject as extra context into the evolution analysis
        $summary = $this->buildFeedbackSummary($negativeFeedback);

        // Temporarily patch the agent's goal with the feedback context so the
        // evolution action can use it without requiring a new method signature.
        // The original agent is not mutated — we operate on a clone.
        $agentWithContext = clone $agent;
        $agentWithContext->goal = $agent->goal."\n\n[Feedback Summary for Analysis]\n".$summary;

        return $this->analyzeEvolution->execute($agentWithContext, null);
    }

    /**
     * Build a human-readable summary of failure patterns from feedback records.
     *
     * @param  Collection<int, AgentFeedback>  $feedbacks
     */
    private function buildFeedbackSummary(Collection $feedbacks): string
    {
        $parts = ["Recent negative feedback ({$feedbacks->count()} items):"];

        $labelCounts = $feedbacks
            ->whereNotNull('label')
            ->groupBy('label')
            ->map(fn ($group) => $group->count());

        if ($labelCounts->isNotEmpty()) {
            $parts[] = 'Failure categories: '.$labelCounts
                ->map(fn ($count, $label) => "{$label} ({$count})")
                ->implode(', ');
        }

        $comments = $feedbacks->whereNotNull('comment')->take(5);
        foreach ($comments as $fb) {
            $parts[] = '- '.mb_substr($fb->comment, 0, 200);
        }

        $corrections = $feedbacks->whereNotNull('correction')->take(3);
        foreach ($corrections as $fb) {
            $parts[] = 'Correction example: '.mb_substr($fb->correction, 0, 300);
        }

        return implode("\n", $parts);
    }
}
