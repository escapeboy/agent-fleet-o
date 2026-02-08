<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkflowAction
{
    /**
     * @param  array  $nodes  Array of node definitions: [{type, label, agent_id?, skill_id?, position_x, position_y, config}]
     * @param  array  $edges  Array of edge definitions: [{source_node_index, target_node_index, condition?, label?, is_default?}]
     */
    public function execute(
        string $userId,
        string $name,
        ?string $description = null,
        array $nodes = [],
        array $edges = [],
        int $maxLoopIterations = 5,
        ?string $teamId = null,
    ): Workflow {
        return DB::transaction(function () use ($userId, $name, $description, $nodes, $edges, $maxLoopIterations, $teamId) {
            $workflow = Workflow::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'name' => $name,
                'slug' => Str::slug($name) . '-' . Str::random(6),
                'description' => $description,
                'status' => WorkflowStatus::Draft,
                'max_loop_iterations' => $maxLoopIterations,
            ]);

            // If no nodes provided, create default start + end
            if (empty($nodes)) {
                $nodes = [
                    ['type' => 'start', 'label' => 'Start', 'position_x' => 250, 'position_y' => 50],
                    ['type' => 'end', 'label' => 'End', 'position_x' => 250, 'position_y' => 400],
                ];
            }

            $createdNodes = [];

            foreach ($nodes as $index => $nodeData) {
                $createdNodes[$index] = WorkflowNode::create([
                    'workflow_id' => $workflow->id,
                    'agent_id' => $nodeData['agent_id'] ?? null,
                    'skill_id' => $nodeData['skill_id'] ?? null,
                    'type' => $nodeData['type'],
                    'label' => $nodeData['label'],
                    'position_x' => $nodeData['position_x'] ?? 0,
                    'position_y' => $nodeData['position_y'] ?? 0,
                    'config' => $nodeData['config'] ?? [],
                    'order' => $index,
                ]);
            }

            foreach ($edges as $edgeData) {
                $sourceNode = $createdNodes[$edgeData['source_node_index']] ?? null;
                $targetNode = $createdNodes[$edgeData['target_node_index']] ?? null;

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

            activity()
                ->performedOn($workflow)
                ->withProperties(['node_count' => count($nodes), 'edge_count' => count($edges)])
                ->log('workflow.created');

            return $workflow;
        });
    }
}
