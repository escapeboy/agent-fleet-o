<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaybookStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'experiment_id' => $this->experiment_id,
            'workflow_node_id' => $this->workflow_node_id,
            'agent_id' => $this->agent_id,
            'skill_id' => $this->skill_id,
            'crew_id' => $this->crew_id,
            'order' => $this->order,
            'status' => $this->status,
            'input_prompt' => $this->input_prompt,
            'output' => $this->output,
            'error_message' => $this->error_message,
            'duration_ms' => $this->duration_ms,
            'cost_credits' => $this->cost_credits,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
