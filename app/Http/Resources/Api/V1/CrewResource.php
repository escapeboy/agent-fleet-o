<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'process_type' => $this->process_type->value,
            'status' => $this->status->value,
            'max_task_iterations' => $this->max_task_iterations,
            'quality_threshold' => $this->quality_threshold,
            'settings' => $this->settings,
            'coordinator' => $this->whenLoaded('coordinator', fn () => [
                'id' => $this->coordinator->id,
                'name' => $this->coordinator->name,
                'slug' => $this->coordinator->slug,
            ]),
            'qa_agent' => $this->whenLoaded('qaAgent', fn () => [
                'id' => $this->qaAgent->id,
                'name' => $this->qaAgent->name,
                'slug' => $this->qaAgent->slug,
            ]),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'id' => $m->id,
                'agent_id' => $m->agent_id,
                'agent_name' => $m->agent?->name,
                'role' => $m->role->value,
                'sort_order' => $m->sort_order,
                'config' => $m->config,
            ])),
            'executions_count' => $this->whenCounted('executions', fn () => $this->executions_count),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
