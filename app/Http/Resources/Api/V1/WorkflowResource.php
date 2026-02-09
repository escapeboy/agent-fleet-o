<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'version' => $this->version,
            'max_loop_iterations' => $this->max_loop_iterations,
            'estimated_cost_credits' => $this->estimated_cost_credits,
            'settings' => $this->settings,
            'node_count' => $this->whenCounted('nodes', fn () => $this->nodes_count),
            'nodes' => $this->whenLoaded('nodes', fn () => $this->nodes->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type->value,
                'label' => $n->label,
                'agent_id' => $n->agent_id,
                'skill_id' => $n->skill_id,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
                'config' => $n->config,
                'order' => $n->order,
            ])),
            'edges' => $this->whenLoaded('edges', fn () => $this->edges->map(fn ($e) => [
                'id' => $e->id,
                'source_node_id' => $e->source_node_id,
                'target_node_id' => $e->target_node_id,
                'condition' => $e->condition,
                'label' => $e->label,
                'is_default' => $e->is_default,
                'sort_order' => $e->sort_order,
            ])),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
