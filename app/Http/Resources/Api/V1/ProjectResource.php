<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'goal' => $this->goal,
            'workflow_id' => $this->workflow_id,
            'crew_id' => $this->crew_id,
            'agent_config' => $this->agent_config,
            'budget_config' => $this->budget_config,
            'delivery_config' => $this->delivery_config,
            'notification_config' => $this->notification_config,
            'settings' => $this->settings,
            'allowed_tool_ids' => $this->allowed_tool_ids,
            'allowed_credential_ids' => $this->allowed_credential_ids,
            'total_runs' => $this->total_runs,
            'successful_runs' => $this->successful_runs,
            'failed_runs' => $this->failed_runs,
            'total_spend_credits' => $this->total_spend_credits,
            'started_at' => $this->started_at?->toISOString(),
            'paused_at' => $this->paused_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'last_run_at' => $this->last_run_at?->toISOString(),
            'next_run_at' => $this->next_run_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'schedule' => $this->whenLoaded('schedule'),
            'latest_run' => new ProjectRunResource($this->whenLoaded('latestRun')),
        ];
    }
}
