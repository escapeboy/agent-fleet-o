<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\Bus;
use Livewire\Component;

class ExperimentTasksPanel extends Component
{
    public Experiment $experiment;
    public ?string $expandedTaskId = null;

    public function toggleTask(string $taskId): void
    {
        $this->expandedTaskId = $this->expandedTaskId === $taskId ? null : $taskId;
    }

    public function render()
    {
        $tasks = $this->experiment->tasks()->orderBy('sort_order')->get();

        $total = $tasks->count();
        $completed = $tasks->where('status', ExperimentTaskStatus::Completed)->count();
        $running = $tasks->where('status', ExperimentTaskStatus::Running)->count();
        $failed = $tasks->where('status', ExperimentTaskStatus::Failed)->count();

        // Try to load batch info from stage output
        $batchInfo = null;
        $buildingStage = $this->experiment->stages()
            ->where('stage', 'building')
            ->latest()
            ->first();

        $batchId = $buildingStage?->output_snapshot['batch_id'] ?? null;
        if ($batchId) {
            try {
                $batchInfo = Bus::findBatch($batchId);
            } catch (\Throwable) {
                // Batch not found or bus table missing
            }
        }

        return view('livewire.experiments.experiment-tasks-panel', [
            'tasks' => $tasks,
            'total' => $total,
            'completed' => $completed,
            'running' => $running,
            'failed' => $failed,
            'batchInfo' => $batchInfo,
        ]);
    }
}
