<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrewExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'crew_id' => $this->crew_id,
            'experiment_id' => $this->experiment_id,
            'goal' => $this->goal,
            'status' => $this->status->value,
            'task_plan' => $this->task_plan,
            'final_output' => $this->final_output,
            'config_snapshot' => $this->config_snapshot,
            'quality_score' => $this->quality_score,
            'coordinator_iterations' => $this->coordinator_iterations,
            'total_cost_credits' => $this->total_cost_credits,
            'duration_ms' => $this->duration_ms,
            'error_message' => $this->error_message,
            'task_executions' => $this->whenLoaded('taskExecutions', fn () => $this->taskExecutions->map(fn ($t) => [
                'id' => $t->id,
                'agent_id' => $t->agent_id,
                'title' => $t->title,
                'description' => $t->description,
                'status' => $t->status->value,
                'input_context' => $t->input_context,
                'output' => $t->output,
                'qa_feedback' => $t->qa_feedback,
                'qa_score' => $t->qa_score,
                'attempt_number' => $t->attempt_number,
                'max_attempts' => $t->max_attempts,
                'cost_credits' => $t->cost_credits,
                'duration_ms' => $t->duration_ms,
                'error_message' => $t->error_message,
                'sort_order' => $t->sort_order,
                'started_at' => $t->started_at?->toISOString(),
                'completed_at' => $t->completed_at?->toISOString(),
            ])),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
