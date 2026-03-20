<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\WorkflowSnapshot;
use Livewire\Component;

class WorkflowTimeline extends Component
{
    public string $experimentId;

    public ?array $selectedSnapshot = null;

    public function mount(string $experimentId): void
    {
        $this->experimentId = $experimentId;
    }

    public function selectSnapshot(string $snapshotId): void
    {
        $snapshot = WorkflowSnapshot::find($snapshotId);

        if ($snapshot && $snapshot->experiment_id === $this->experimentId) {
            $this->selectedSnapshot = [
                'id' => $snapshot->id,
                'event_type' => $snapshot->event_type,
                'sequence' => $snapshot->sequence,
                'graph_state' => $snapshot->graph_state,
                'step_input' => $snapshot->step_input,
                'step_output' => $snapshot->step_output,
                'metadata' => $snapshot->metadata,
                'duration_from_start_ms' => $snapshot->duration_from_start_ms,
                'created_at' => $snapshot->created_at->toIso8601String(),
            ];
        }
    }

    public function clearSelection(): void
    {
        $this->selectedSnapshot = null;
    }

    public function render()
    {
        $snapshots = WorkflowSnapshot::where('experiment_id', $this->experimentId)
            ->orderBy('sequence')
            ->get();

        return view('livewire.experiments.workflow-timeline', [
            'snapshots' => $snapshots,
        ]);
    }
}
