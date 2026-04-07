<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Approval\Actions\CreateHumanTaskAction;
use App\Domain\Crew\Jobs\ExecuteCrewWorkflowNodeJob;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Workflow\Actions\DispatchSubWorkflowAction;
use App\Domain\Workflow\Actions\HandleTimeGateAction;
use App\Domain\Workflow\Jobs\ExecuteWorkflowNodeJob;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class WorkflowNodeDispatcher
{
    public function dispatchBatch(Experiment $experiment, array $nodeIds, array $graph, $steps): void
    {
        $jobs = [];
        $dispatchedNodeIds = [];
        $adjacency = WorkflowGraphAnalyzer::buildAdjacencyMap($graph['edges']);
        $nodeMap = collect($graph['nodes'])->keyBy('id')->toArray();

        $priorities = WorkflowGraphAnalyzer::calculateNodePriorities($nodeIds, $adjacency, $nodeMap);
        arsort($priorities);
        $sortedNodeIds = array_keys($priorities);

        Log::info('WorkflowGraphExecutor: Node priorities', [
            'experiment_id' => $experiment->id,
            'priorities' => $priorities,
        ]);

        foreach ($sortedNodeIds as $nodeId) {
            $step = $steps[$nodeId] ?? null;

            if (! $step || (! $step->isPending() && $step->status !== 'running')) {
                continue;
            }

            $nodeType = $nodeMap[$nodeId]['type'] ?? 'agent';

            if ($nodeType === 'human_task') {
                $this->dispatchHumanTask($step, $experiment, $nodeMap[$nodeId]);
                $dispatchedNodeIds[] = $nodeId;

                continue;
            }

            if ($nodeType === 'time_gate') {
                $this->dispatchTimeGate($step, $experiment, $nodeMap[$nodeId]);
                $dispatchedNodeIds[] = $nodeId;

                continue;
            }

            if ($nodeType === 'sub_workflow') {
                $this->dispatchSubWorkflow($step, $experiment, $nodeMap[$nodeId]);
                $dispatchedNodeIds[] = $nodeId;

                continue;
            }

            if ($nodeType === 'crew') {
                $jobs[] = new ExecuteCrewWorkflowNodeJob($step->id, $experiment->id, $experiment->team_id);
            } elseif (in_array($nodeType, ['llm', 'http_request', 'parameter_extractor', 'variable_aggregator', 'template_transform', 'knowledge_retrieval'], true)) {
                $jobs[] = new ExecuteWorkflowNodeJob($step->id, $experiment->id, $experiment->team_id);
            } else {
                $jobs[] = new ExecutePlaybookStepJob($step->id, $experiment->id, $experiment->team_id);
            }

            $dispatchedNodeIds[] = $nodeId;
        }

        if (empty($jobs)) {
            return;
        }

        $experimentId = $experiment->id;

        Log::info('WorkflowGraphExecutor: Dispatching node batch', [
            'experiment_id' => $experimentId,
            'node_count' => count($jobs),
            'node_ids' => $dispatchedNodeIds,
        ]);

        Bus::batch($jobs)
            ->name("workflow:{$experimentId}:batch:".implode('-', array_slice($dispatchedNodeIds, 0, 3)))
            ->onQueue('experiments')
            ->allowFailures()
            ->then(function () use ($experimentId, $dispatchedNodeIds) {
                WorkflowGraphExecutor::continueAfterBatchStatic($experimentId, $dispatchedNodeIds);
            })
            ->catch(function () use ($experimentId) {
                WorkflowGraphExecutor::handleBatchFailureStatic($experimentId);
            })
            ->dispatch();
    }

    public function dispatchHumanTask(PlaybookStep $step, Experiment $experiment, array $nodeData): void
    {
        $workflowNode = WorkflowNode::find($step->workflow_node_id);

        if (! $workflowNode) {
            Log::warning('WorkflowGraphExecutor: Human task node not found', [
                'step_id' => $step->id,
                'workflow_node_id' => $step->workflow_node_id,
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Workflow node not found for human task',
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            app(CreateHumanTaskAction::class)->execute($experiment, $step, $workflowNode);

            Log::info('WorkflowGraphExecutor: Human task created', [
                'step_id' => $step->id,
                'experiment_id' => $experiment->id,
                'node_label' => $nodeData['label'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: Failed to create human task', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Failed to create human task: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    public function dispatchTimeGate(PlaybookStep $step, Experiment $experiment, array $nodeData): void
    {
        try {
            app(HandleTimeGateAction::class)->execute($step, $experiment, $nodeData);
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: Failed to activate time gate', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Failed to activate time gate: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    public function dispatchSubWorkflow(PlaybookStep $step, Experiment $experiment, array $nodeData): void
    {
        try {
            app(DispatchSubWorkflowAction::class)->execute($step, $experiment, $nodeData);
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: Failed to dispatch sub-workflow', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Failed to dispatch sub-workflow: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
