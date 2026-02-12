<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'transport_config' => $this->transport_config,
            'tool_definitions' => $this->tool_definitions,
            'settings' => $this->settings,
            'health_status' => $this->health_status,
            'last_health_check' => $this->last_health_check?->toISOString(),
            'function_count' => $this->functionCount(),
            'agents_count' => $this->whenCounted('agents'),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
