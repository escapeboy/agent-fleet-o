<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\WorkflowNodeEvent;
use Illuminate\Database\Eloquent\Collection;

class WorkflowEventRecorder
{
    /**
     * Record that a step started executing.
     * Returns the created event so callers can store the ID and update it later.
     */
    public function recordStarted(
        PlaybookStep $step,
        string $nodeType,
        string $nodeLabel,
        ?string $rootEventId = null,
        ?string $parentEventId = null,
        ?string $inputSummary = null,
    ): WorkflowNodeEvent {
        $event = WorkflowNodeEvent::create([
            'experiment_id' => $step->experiment_id,
            'playbook_step_id' => $step->id,
            'workflow_node_id' => $step->workflow_node_id,
            'node_type' => $nodeType,
            'node_label' => $nodeLabel,
            'event_type' => 'started',
            'root_event_id' => $rootEventId,
            'parent_event_id' => $parentEventId,
            'input_summary' => $inputSummary,
        ]);

        // If no root_event_id, this IS the root — point to self
        if (! $rootEventId) {
            $event->update(['root_event_id' => $event->id]);
        }

        // Persist root_event_id on the step for downstream propagation
        if (! $step->root_event_id) {
            $step->update(['root_event_id' => $event->root_event_id ?? $event->id]);
        }

        return $event;
    }

    /**
     * Update an existing event to "completed".
     */
    public function recordCompleted(
        WorkflowNodeEvent $event,
        int $durationMs,
        ?string $outputSummary = null,
    ): void {
        $event->update([
            'event_type' => 'completed',
            'output_summary' => $outputSummary,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Update an existing event to "failed".
     */
    public function recordFailed(
        WorkflowNodeEvent $event,
        string $errorMessage,
        int $durationMs = 0,
    ): void {
        $event->update([
            'event_type' => 'failed',
            'output_summary' => $errorMessage,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Record a terminal event directly (for single-moment events like waiting_time, skipped).
     */
    public function recordEvent(
        PlaybookStep $step,
        string $nodeType,
        string $nodeLabel,
        string $eventType,
        ?string $rootEventId = null,
        ?string $summary = null,
    ): WorkflowNodeEvent {
        return WorkflowNodeEvent::create([
            'experiment_id' => $step->experiment_id,
            'playbook_step_id' => $step->id,
            'workflow_node_id' => $step->workflow_node_id,
            'node_type' => $nodeType,
            'node_label' => $nodeLabel,
            'event_type' => $eventType,
            'root_event_id' => $rootEventId ?? $step->root_event_id,
            'output_summary' => $summary,
        ]);
    }

    /**
     * Get the full execution chain for an experiment, ordered chronologically.
     *
     * @return Collection<WorkflowNodeEvent>
     */
    public function getChain(string $experimentId): Collection
    {
        return WorkflowNodeEvent::where('experiment_id', $experimentId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get summary statistics for an experiment's execution chain.
     */
    public function getStats(string $experimentId): array
    {
        $events = WorkflowNodeEvent::where('experiment_id', $experimentId)->get();

        return [
            'total_events' => $events->count(),
            'completed' => $events->where('event_type', 'completed')->count(),
            'failed' => $events->where('event_type', 'failed')->count(),
            'waiting_time' => $events->where('event_type', 'waiting_time')->count(),
            'total_duration_ms' => $events->sum('duration_ms'),
            'avg_step_ms' => $events->where('duration_ms', '>', 0)->avg('duration_ms'),
        ];
    }
}
