<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentFeedback;
use App\Domain\Audit\Models\AuditEntry;

class CreateAgentFeedbackAction
{
    /**
     * Record human feedback on an agent's output.
     *
     * When 5+ consecutive negative feedbacks accumulate, triggers the
     * AnalyzeAgentFeedbackAction to generate an EvolutionProposal.
     */
    public function execute(
        Agent $agent,
        string $teamId,
        FeedbackRating $rating,
        ?string $comment = null,
        ?string $correction = null,
        ?string $outputSnapshot = null,
        ?string $inputSnapshot = null,
        ?string $userId = null,
        ?string $agentExecutionId = null,
        ?string $aiRunId = null,
        ?string $experimentStageId = null,
        ?string $crewTaskExecutionId = null,
        ?string $label = null,
        array $tags = [],
    ): AgentFeedback {
        $feedback = AgentFeedback::create([
            'team_id' => $teamId,
            'agent_id' => $agent->id,
            'ai_run_id' => $aiRunId,
            'agent_execution_id' => $agentExecutionId,
            'crew_task_execution_id' => $crewTaskExecutionId,
            'experiment_stage_id' => $experimentStageId,
            'user_id' => $userId,
            'source' => 'human',
            'feedback_type' => 'binary',
            'score' => $rating->value,
            'label' => $label,
            'correction' => $correction,
            'comment' => $comment,
            'output_snapshot' => $outputSnapshot,
            'input_snapshot' => $inputSnapshot,
            'tags' => $tags,
            'feedback_at' => now(),
        ]);

        AuditEntry::withoutGlobalScopes()->create([
            'user_id' => $userId,
            'team_id' => $teamId,
            'event' => 'feedback.submitted',
            'subject_type' => AgentFeedback::class,
            'subject_id' => $feedback->id,
            'properties' => [
                'agent_id' => $agent->id,
                'rating' => $rating->value,
                'label' => $label,
                'has_correction' => $correction !== null,
                'agent_execution_id' => $agentExecutionId,
            ],
            'created_at' => now(),
        ]);

        // Trigger improvement analysis when enough negative feedback has accumulated.
        if ($rating->isNegative() && $this->shouldTriggerAnalysis($agent, $teamId)) {
            dispatch(new \App\Domain\Agent\Jobs\AnalyzeAgentFeedbackJob($agent));
        }

        return $feedback;
    }

    /**
     * Check if the agent has accumulated enough consecutive negative feedback
     * to warrant an automated improvement analysis.
     */
    private function shouldTriggerAnalysis(Agent $agent, string $teamId): bool
    {
        $recentNegativeCount = AgentFeedback::where('agent_id', $agent->id)
            ->where('team_id', $teamId)
            ->where('score', FeedbackRating::Negative->value)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return $recentNegativeCount >= 5 && $recentNegativeCount % 5 === 0;
    }
}
