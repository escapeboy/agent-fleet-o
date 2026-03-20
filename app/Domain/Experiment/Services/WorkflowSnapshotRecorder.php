<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Models\WorkflowSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkflowSnapshotRecorder
{
    /**
     * Record a workflow execution snapshot.
     *
     * No-op if the team's plan doesn't include time_travel_debugging (cloud)
     * or if recording is disabled via config.
     */
    public function record(
        Experiment $experiment,
        string $eventType,
        ?PlaybookStep $step = null,
        ?array $input = null,
        ?array $output = null,
        ?array $metadata = null,
    ): void {
        if (! $this->isEnabled($experiment)) {
            return;
        }

        try {
            $sequence = $this->nextSequence($experiment->id);
            $durationMs = $this->durationFromStart($experiment);

            WorkflowSnapshot::create([
                'team_id' => $experiment->team_id,
                'experiment_id' => $experiment->id,
                'playbook_step_id' => $step?->id,
                'workflow_node_id' => $step?->workflow_node_id,
                'event_type' => $eventType,
                'sequence' => $sequence,
                'graph_state' => $this->captureGraphState($experiment),
                'step_input' => $input,
                'step_output' => $output,
                'metadata' => $metadata,
                'duration_from_start_ms' => $durationMs,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('WorkflowSnapshotRecorder: failed to record snapshot', [
                'experiment_id' => $experiment->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function captureGraphState(Experiment $experiment): array
    {
        return PlaybookStep::where('experiment_id', $experiment->id)
            ->get()
            ->mapWithKeys(fn (PlaybookStep $step) => [
                $step->workflow_node_id ?? $step->id => [
                    'status' => $step->status,
                    'output_preview' => Str::limit(json_encode($step->output), 500),
                    'cost_credits' => $step->cost_credits ?? 0,
                    'duration_ms' => $step->duration_ms ?? 0,
                    'loop_count' => $step->loop_count ?? 0,
                ],
            ])
            ->toArray();
    }

    /**
     * Check if snapshot recording is enabled for this experiment's team.
     *
     * In base (community edition): always enabled.
     * In cloud: delegates to PlanEnforcer for time_travel_debugging feature.
     */
    protected function isEnabled(Experiment $experiment): bool
    {
        // Cloud override can replace this method to check PlanEnforcer
        return config('workflows.time_travel_enabled', true);
    }

    private function nextSequence(string $experimentId): int
    {
        $max = DB::table('workflow_snapshots')
            ->where('experiment_id', $experimentId)
            ->max('sequence');

        return ($max ?? -1) + 1;
    }

    private function durationFromStart(Experiment $experiment): int
    {
        if (! $experiment->started_at) {
            return 0;
        }

        return (int) now()->diffInMilliseconds($experiment->started_at);
    }
}
