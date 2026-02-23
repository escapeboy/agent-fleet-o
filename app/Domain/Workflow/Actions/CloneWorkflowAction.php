<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CloneWorkflowAction
{
    /**
     * Deep-clone a workflow to a target team (or same team).
     * Replaces agent_id/skill_id references to null (must be reassigned).
     */
    public function execute(
        Workflow $source,
        string $teamId,
        string $userId,
        ?string $newName = null,
    ): Workflow {
        return DB::transaction(function () use ($source, $teamId, $userId, $newName) {
            $name = $newName ?? $source->name.' (Copy)';

            $workflow = Workflow::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(6),
                'description' => $source->description,
                'status' => WorkflowStatus::Draft,
                'version' => 1,
                'max_loop_iterations' => $source->max_loop_iterations,
                'estimated_cost_credits' => $source->estimated_cost_credits,
                'settings' => $source->settings,
            ]);

            $nodeIdMap = [];

            foreach ($source->nodes as $node) {
                $newNode = WorkflowNode::create([
                    'workflow_id' => $workflow->id,
                    'agent_id' => null, // team-specific, must be reassigned
                    'skill_id' => null,
                    'type' => $node->type,
                    'label' => $node->label,
                    'position_x' => $node->position_x,
                    'position_y' => $node->position_y,
                    'config' => $node->config,
                    'order' => $node->order,
                ]);
                $nodeIdMap[$node->id] = $newNode->id;
            }

            foreach ($source->edges as $edge) {
                $sourceId = $nodeIdMap[$edge->source_node_id] ?? null;
                $targetId = $nodeIdMap[$edge->target_node_id] ?? null;

                if ($sourceId && $targetId) {
                    WorkflowEdge::create([
                        'workflow_id' => $workflow->id,
                        'source_node_id' => $sourceId,
                        'target_node_id' => $targetId,
                        'condition' => $edge->condition,
                        'label' => $edge->label,
                        'is_default' => $edge->is_default,
                        'sort_order' => $edge->sort_order,
                    ]);
                }
            }

            return $workflow;
        });
    }
}
