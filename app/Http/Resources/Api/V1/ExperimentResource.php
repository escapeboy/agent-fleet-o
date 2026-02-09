<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExperimentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'thesis' => $this->thesis,
            'track' => $this->track->value,
            'status' => $this->status->value,
            'paused_from_status' => $this->paused_from_status,
            'constraints' => $this->constraints,
            'success_criteria' => $this->success_criteria,
            'budget_cap_credits' => $this->budget_cap_credits,
            'budget_spent_credits' => $this->budget_spent_credits,
            'max_iterations' => $this->max_iterations,
            'current_iteration' => $this->current_iteration,
            'max_outbound_count' => $this->max_outbound_count,
            'outbound_count' => $this->outbound_count,
            'workflow_id' => $this->workflow_id,
            'user_id' => $this->user_id,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'killed_at' => $this->killed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
