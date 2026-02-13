<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Workflow\Actions\MaterializeWorkflowAction;
use App\Domain\Workflow\Models\Workflow;

class CreateExperimentAction
{
    public function __construct(
        private MaterializeWorkflowAction $materializeAction,
    ) {}

    public function execute(
        string $userId,
        string $title,
        string $thesis,
        string $track,
        int $budgetCapCredits = 10000,
        int $maxIterations = 3,
        int $maxOutboundCount = 100,
        array $constraints = [],
        array $successCriteria = [],
        ?string $teamId = null,
        ?string $workflowId = null,
    ): Experiment {
        $experiment = Experiment::create([
            'user_id' => $userId,
            'team_id' => $teamId,
            'title' => $title,
            'thesis' => $thesis,
            'track' => $track,
            'status' => ExperimentStatus::Draft,
            'constraints' => array_merge([
                'max_retries_per_stage' => 3,
                'max_rejection_cycles' => 3,
                'auto_approve' => true,
            ], $constraints),
            'success_criteria' => $successCriteria,
            'budget_cap_credits' => $budgetCapCredits,
            'budget_spent_credits' => 0,
            'max_iterations' => $maxIterations,
            'current_iteration' => 1,
            'max_outbound_count' => $maxOutboundCount,
            'outbound_count' => 0,
        ]);

        // If a workflow is provided, materialize its graph into PlaybookSteps
        if ($workflowId) {
            $workflow = Workflow::withoutGlobalScopes()->find($workflowId);

            if ($workflow) {
                $this->materializeAction->execute($experiment, $workflow);
            }
        }

        return $experiment;
    }
}
