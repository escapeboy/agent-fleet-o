<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Workflow\Services\WorkflowSuggestionEngine;

class SuggestWorkflowAction
{
    public function __construct(
        private readonly WorkflowSuggestionEngine $engine,
    ) {}

    /**
     * Analyze a workflow experiment and return optimization suggestions.
     *
     * @return array<int, array>
     */
    public function execute(Experiment $experiment): array
    {
        return $this->engine->analyze($experiment);
    }

    /**
     * Turn a suggestion into an EvolutionProposal for an agent.
     */
    public function createProposal(Experiment $experiment, array $suggestion, string $userId): EvolutionProposal
    {
        // Find the agent linked to the suggested step
        $agentId = null;
        if (!empty($suggestion['step_id'])) {
            $step = $experiment->playbookSteps()
                ->where('id', $suggestion['step_id'])
                ->first();
            $agentId = $step?->agent_id;
        }

        // Fall back to the experiment's primary agent
        if (!$agentId) {
            $agentId = $experiment->agent_id ?? $experiment->playbookSteps()->value('agent_id');
        }

        return EvolutionProposal::create([
            'team_id' => $experiment->team_id,
            'agent_id' => $agentId,
            'status' => EvolutionProposalStatus::Pending,
            'analysis' => "Workflow optimization suggestion from experiment \"{$experiment->title}\".",
            'proposed_changes' => $suggestion,
            'reasoning' => $suggestion['reason'] ?? '',
            'confidence_score' => 0.75,
        ]);
    }
}
