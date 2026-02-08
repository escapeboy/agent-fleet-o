<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\DB;

class UpdateWorkflowAction
{
    /**
     * Update a workflow's metadata and/or its graph.
     *
     * If the workflow is active (not draft), incrementing the version.
     * Nodes and edges are replaced wholesale when provided.
     */
    public function execute(
        Workflow $workflow,
        ?string $name = null,
        ?string $description = null,
        ?int $maxLoopIterations = null,
        ?array $nodes = null,
        ?array $edges = null,
    ): Workflow {
        return DB::transaction(function () use ($workflow, $name, $description, $maxLoopIterations, $nodes, $edges) {
            $changes = [];

            if ($name !== null && $name !== $workflow->name) {
                $changes['name'] = $name;
                $workflow->name = $name;
            }

            if ($description !== null) {
                $changes['description'] = 'updated';
                $workflow->description = $description;
            }

            if ($maxLoopIterations !== null) {
                $changes['max_loop_iterations'] = $maxLoopIterations;
                $workflow->max_loop_iterations = $maxLoopIterations;
            }

            // If graph is being updated and workflow is active, bump version
            if ($nodes !== null && $workflow->isActive()) {
                $workflow->version = $workflow->version + 1;
                $changes['version'] = $workflow->version;
            }

            $workflow->save();

            // Replace graph if nodes provided
            if ($nodes !== null) {
                $this->replaceGraph($workflow, $nodes, $edges ?? []);
                $changes['graph'] = 'replaced';
            }

            if (! empty($changes)) {
                activity()
                    ->performedOn($workflow)
                    ->withProperties($changes)
                    ->log('workflow.updated');
            }

            return $workflow->fresh();
        });
    }

    private function replaceGraph(Workflow $workflow, array $nodes, array $edges): void
    {
        // Delete existing graph
        $workflow->edges()->delete();
        $workflow->nodes()->delete();

        // Create new nodes
        $createdNodes = [];

        foreach ($nodes as $index => $nodeData) {
            $createdNodes[$nodeData['id'] ?? $index] = WorkflowNode::create([
                'workflow_id' => $workflow->id,
                'agent_id' => $nodeData['agent_id'] ?? null,
                'skill_id' => $nodeData['skill_id'] ?? null,
                'type' => $nodeData['type'],
                'label' => $nodeData['label'],
                'position_x' => $nodeData['position_x'] ?? 0,
                'position_y' => $nodeData['position_y'] ?? 0,
                'config' => $nodeData['config'] ?? [],
                'order' => $nodeData['order'] ?? $index,
            ]);
        }

        // Create new edges
        foreach ($edges as $edgeData) {
            $sourceNode = $createdNodes[$edgeData['source_node_id']] ?? null;
            $targetNode = $createdNodes[$edgeData['target_node_id']] ?? null;

            if (! $sourceNode || ! $targetNode) {
                continue;
            }

            WorkflowEdge::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $sourceNode->id,
                'target_node_id' => $targetNode->id,
                'condition' => $edgeData['condition'] ?? null,
                'label' => $edgeData['label'] ?? null,
                'is_default' => $edgeData['is_default'] ?? false,
                'sort_order' => $edgeData['sort_order'] ?? 0,
            ]);
        }
    }
}
