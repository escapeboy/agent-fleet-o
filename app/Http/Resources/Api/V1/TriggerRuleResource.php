<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TriggerRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'project_id' => $this->project_id,
            'source_type' => $this->source_type,
            'conditions' => $this->conditions,
            'input_mapping' => $this->input_mapping,
            'cooldown_seconds' => $this->cooldown_seconds,
            'max_concurrent' => $this->max_concurrent,
            'status' => $this->status->value,
            'total_triggers' => $this->total_triggers,
            'last_triggered_at' => $this->last_triggered_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
