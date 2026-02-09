<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Experiment\Enums\ExecutionMode;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaterializeWorkflowAction
{
    /**
     * Convert a workflow graph into concrete PlaybookStep rows for an experiment.
     *
     * This creates the execution plan by:
     * 1. Snapshotting the workflow graph into experiment constraints
     * 2. Creating a PlaybookStep for each agent node
     * 3. Deriving execution mode from graph topology
     */
    public function execute(Experiment $experiment, Workflow $workflow): Collection
    {
        return DB::transaction(function () use ($experiment, $workflow) {
            $workflow->load(['nodes', 'edges']);

            // Snapshot workflow graph into experiment
            $graphSnapshot = $this->buildGraphSnapshot($workflow);
            $experiment->update([
                'workflow_id' => $workflow->id,
                'workflow_version' => $workflow->version,
                'constraints' => array_merge($experiment->constraints ?? [], [
                    'workflow_graph' => $graphSnapshot,
                ]),
            ]);

            // Create PlaybookSteps for agent and crew nodes
            $agentNodes = $workflow->nodes
                ->whereIn('type', [WorkflowNodeType::Agent, WorkflowNodeType::Crew])
                ->sortBy('order');

            $steps = collect();
            $order = 0;

            foreach ($agentNodes as $node) {
                $executionMode = $this->determineExecutionMode($node, $workflow);

                $step = PlaybookStep::create([
                    'experiment_id' => $experiment->id,
                    'agent_id' => $node->agent_id,
                    'skill_id' => $node->skill_id,
                    'crew_id' => $node->crew_id,
                    'workflow_node_id' => $node->id,
                    'order' => $order++,
                    'execution_mode' => $executionMode,
                    'group_id' => $this->resolveGroupId($node, $workflow),
                    'input_mapping' => $node->config['input_template'] ?? [],
                    'status' => 'pending',
                ]);

                $steps->push($step);
            }

            activity()
                ->performedOn($experiment)
                ->withProperties([
                    'workflow_id' => $workflow->id,
                    'workflow_version' => $workflow->version,
                    'step_count' => $steps->count(),
                ])
                ->log('workflow.materialized');

            return $steps;
        });
    }

    private function buildGraphSnapshot(Workflow $workflow): array
    {
        return [
            'workflow_id' => $workflow->id,
            'version' => $workflow->version,
            'max_loop_iterations' => $workflow->max_loop_iterations,
            'nodes' => $workflow->nodes->map(fn ($node) => [
                'id' => $node->id,
                'type' => $node->type->value,
                'label' => $node->label,
                'agent_id' => $node->agent_id,
                'skill_id' => $node->skill_id,
                'crew_id' => $node->crew_id,
                'config' => $node->config,
                'order' => $node->order,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
            ])->toArray(),
            'edges' => $workflow->edges->map(fn ($edge) => [
                'id' => $edge->id,
                'source_node_id' => $edge->source_node_id,
                'target_node_id' => $edge->target_node_id,
                'condition' => $edge->condition,
                'label' => $edge->label,
                'is_default' => $edge->is_default,
                'sort_order' => $edge->sort_order,
            ])->toArray(),
        ];
    }

    /**
     * Determine if a node should run sequentially or in parallel.
     *
     * A node runs in parallel if it shares the same predecessor set as another agent node.
     */
    private function determineExecutionMode($node, Workflow $workflow): ExecutionMode
    {
        $predecessors = $workflow->edges
            ->where('target_node_id', $node->id)
            ->pluck('source_node_id')
            ->sort()
            ->values()
            ->toArray();

        // Find other executable nodes (agent or crew) with the same predecessors
        $siblings = $workflow->nodes
            ->whereIn('type', [WorkflowNodeType::Agent, WorkflowNodeType::Crew])
            ->where('id', '!=', $node->id)
            ->filter(function ($sibling) use ($workflow, $predecessors) {
                $siblingPreds = $workflow->edges
                    ->where('target_node_id', $sibling->id)
                    ->pluck('source_node_id')
                    ->sort()
                    ->values()
                    ->toArray();

                return $siblingPreds === $predecessors;
            });

        return $siblings->isNotEmpty() ? ExecutionMode::Parallel : ExecutionMode::Sequential;
    }

    /**
     * Generate a group ID for parallel execution grouping.
     */
    private function resolveGroupId($node, Workflow $workflow): ?string
    {
        $predecessors = $workflow->edges
            ->where('target_node_id', $node->id)
            ->pluck('source_node_id')
            ->sort()
            ->implode('-');

        return $predecessors ?: null;
    }
}
