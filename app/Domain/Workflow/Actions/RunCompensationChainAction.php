<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Events\CompensationCompleted;
use App\Domain\Workflow\Events\CompensationStarted;
use App\Domain\Workflow\Jobs\ExecuteCompensationJob;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\Log;

/**
 * Runs the compensation chain (Saga pattern) for a failed workflow experiment.
 *
 * Collects all completed PlaybookSteps whose workflow node has a compensation_node_id,
 * then dispatches ExecuteCompensationJob for each in reverse completion order.
 *
 * Only fires on `failed` experiments — not on manual kill or expire.
 * Compensation nodes cannot have their own compensation_node_id (prevents recursion).
 *
 * Max depth: config('workflow.max_compensation_depth', 20)
 */
class RunCompensationChainAction
{
    public function execute(Experiment $experiment): void
    {
        // Only compensate failed experiments — not manual kills
        if ($experiment->status->value !== 'execution_failed' && $experiment->status->value !== 'failed') {
            return;
        }

        $maxDepth = (int) config('workflow.max_compensation_depth', 20);

        // Find completed steps with compensation nodes, ordered by completion time DESC
        $stepsWithCompensation = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('status', 'completed')
            ->whereNotNull('workflow_node_id')
            ->orderByDesc('completed_at')
            ->get()
            ->filter(function (PlaybookStep $step) {
                $node = WorkflowNode::select('id', 'compensation_node_id')
                    ->find($step->workflow_node_id);

                return $node && $node->compensation_node_id !== null;
            })
            ->take($maxDepth);

        $total = $stepsWithCompensation->count();

        if ($total === 0) {
            return;
        }

        Log::info('RunCompensationChainAction: starting saga compensation', [
            'experiment_id' => $experiment->id,
            'total_compensations' => $total,
        ]);

        event(new CompensationStarted($experiment, $total));

        $dispatched = 0;
        foreach ($stepsWithCompensation as $step) {
            $node = WorkflowNode::find($step->workflow_node_id);

            if (! $node?->compensation_node_id) {
                continue;
            }

            dispatch(new ExecuteCompensationJob(
                compensationNodeId: $node->compensation_node_id,
                originalStepId: $step->id,
                experimentId: $experiment->id,
            ));

            $dispatched++;
        }

        // Fire completed event after all jobs are dispatched
        // (In a sync queue context this fires after all jobs complete)
        event(new CompensationCompleted(
            experiment: $experiment,
            totalCompensations: $total,
            succeededCount: $dispatched,
            failedCount: $total - $dispatched,
        ));
    }
}
