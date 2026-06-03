<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class AgentResource extends FleetQResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'role' => $this->role,
            'goal' => $this->goal,
            'backstory' => $this->backstory,
            'taste' => $this->taste,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status->value,
            'config' => $this->config,
            'capabilities' => $this->capabilities,
            'constraints' => $this->constraints,
            // Access via ->resource: larastan's checkModelProperties can't resolve
            // the newer `charter` attribute through the bare JsonResource proxy.
            'charter' => $this->resource->charter,
            'tool_profile' => $this->tool_profile,
            'environment' => $this->environment?->value,
            'budget_cap_credits' => $this->budget_cap_credits,
            'budget_spent_credits' => $this->budget_spent_credits,
            'last_health_check' => $this->last_health_check?->toISOString(),
            'skills' => $this->whenLoaded('skills', fn () => $this->skills->map(fn ($skill) => [
                'id' => $skill->id,
                'name' => $skill->name,
                'type' => $skill->type->value,
                'priority' => $skill->pivot->priority,
            ])),
            'tools' => $this->whenLoaded('tools', fn () => $this->tools->map(fn ($tool) => [
                'id' => $tool->id,
                'name' => $tool->name,
                'slug' => $tool->slug,
            ])),
            'runtime_state' => $this->whenLoaded('runtimeState', fn () => $this->runtimeState ? [
                'total_executions' => $this->runtimeState->total_executions,
                'total_input_tokens' => $this->runtimeState->total_input_tokens,
                'total_output_tokens' => $this->runtimeState->total_output_tokens,
                'total_cost_credits' => $this->runtimeState->total_cost_credits,
                'last_active_at' => $this->runtimeState->last_active_at?->toISOString(),
            ] : null),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
