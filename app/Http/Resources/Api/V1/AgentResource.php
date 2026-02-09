<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
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
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status->value,
            'config' => $this->config,
            'capabilities' => $this->capabilities,
            'constraints' => $this->constraints,
            'budget_cap_credits' => $this->budget_cap_credits,
            'budget_spent_credits' => $this->budget_spent_credits,
            'last_health_check' => $this->last_health_check?->toISOString(),
            'skills' => $this->whenLoaded('skills', fn () => $this->skills->map(fn ($skill) => [
                'id' => $skill->id,
                'name' => $skill->name,
                'type' => $skill->type->value,
                'priority' => $skill->pivot->priority,
            ])),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
