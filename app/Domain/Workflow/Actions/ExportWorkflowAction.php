<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;

class ExportWorkflowAction
{
    /**
     * Export a workflow to a portable JSON snapshot.
     */
    public function execute(Workflow $workflow): array
    {
        $nodes = $workflow->nodes()->with(['agent:id,name', 'skill:id,name'])->get();
        $edges = $workflow->edges()->orderBy('sort_order')->get();

        return [
            'version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'workflow' => [
                'name' => $workflow->name,
                'description' => $workflow->description,
                'max_loop_iterations' => $workflow->max_loop_iterations,
                'estimated_cost_credits' => $workflow->estimated_cost_credits,
                'settings' => $workflow->settings ?? [],
                'node_count' => $nodes->count(),
                'agent_node_count' => $nodes->where('type.value', 'agent')->count(),
            ],
            'nodes' => $nodes->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type->value,
                'label' => $n->label,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
                'config' => $n->config ?? [],
                'order' => $n->order,
                'agent_name' => $n->agent?->name,
                'skill_name' => $n->skill?->name,
            ])->values()->toArray(),
            'edges' => $edges->map(fn ($e) => [
                'id' => $e->id,
                'source_node_id' => $e->source_node_id,
                'target_node_id' => $e->target_node_id,
                'condition' => $e->condition,
                'label' => $e->label,
                'is_default' => $e->is_default,
                'sort_order' => $e->sort_order,
            ])->values()->toArray(),
        ];
    }
}
