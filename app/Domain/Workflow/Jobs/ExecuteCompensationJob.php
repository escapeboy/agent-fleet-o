<?php

namespace App\Domain\Workflow\Jobs;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Actions\ExecuteWorkflowStepAction;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Executes a single compensation node as part of a saga rollback.
 * Fires with the original failed step's output as input.
 */
class ExecuteCompensationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly string $compensationNodeId,
        public readonly string $originalStepId,
        public readonly string $experimentId,
    ) {
        $this->onQueue('experiments');
    }

    public function handle(ExecuteWorkflowStepAction $executeStep): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
            return;
        }

        $compensationNode = WorkflowNode::find($this->compensationNodeId);
        $originalStep = PlaybookStep::find($this->originalStepId);

        if (! $compensationNode || ! $originalStep) {
            return;
        }

        $input = array_merge($originalStep->output ?? [], [
            '_compensation' => true,
            '_original_step_id' => $this->originalStepId,
        ]);

        try {
            $result = $executeStep->execute(
                node: [
                    'id' => $compensationNode->id,
                    'type' => $compensationNode->type->value,
                    'label' => $compensationNode->label,
                    'agent_id' => $compensationNode->agent_id,
                    'crew_id' => $compensationNode->crew_id,
                    'config' => $compensationNode->config ?? [],
                ],
                input: $input,
                teamId: $experiment->team_id,
                userId: $experiment->user_id,
                experimentId: $this->experimentId,
            );

            Log::info('ExecuteCompensationJob: compensation step completed', [
                'experiment_id' => $this->experimentId,
                'compensation_node_id' => $this->compensationNodeId,
                'original_step_id' => $this->originalStepId,
            ]);
        } catch (\Throwable $e) {
            Log::error('ExecuteCompensationJob: compensation step failed', [
                'experiment_id' => $this->experimentId,
                'compensation_node_id' => $this->compensationNodeId,
                'original_step_id' => $this->originalStepId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
