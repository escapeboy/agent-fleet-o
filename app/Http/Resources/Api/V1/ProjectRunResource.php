<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'experiment_id' => $this->experiment_id,
            'crew_execution_id' => $this->crew_execution_id,
            'run_number' => $this->run_number,
            'status' => $this->status->value,
            'trigger' => $this->trigger,
            'spend_credits' => $this->spend_credits,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
