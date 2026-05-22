<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SkillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'type' => $this->type->value,
            'execution_type' => $this->execution_type->value,
            'status' => $this->status->value,
            'risk_level' => $this->risk_level->value,
            'input_schema' => $this->input_schema,
            'output_schema' => $this->output_schema,
            'configuration' => $this->configuration,
            'cost_profile' => $this->cost_profile,
            'requires_approval' => $this->requires_approval,
            'system_prompt' => $this->system_prompt,
            'current_version' => $this->current_version,
            'execution_count' => $this->execution_count,
            'success_count' => $this->success_count,
            'avg_latency_ms' => (float) $this->avg_latency_ms,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
