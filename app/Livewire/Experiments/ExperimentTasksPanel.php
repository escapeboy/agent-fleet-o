<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Actions\RetryFromStepAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ExperimentTasksPanel extends Component
{
    public Experiment $experiment;

    public ?string $expandedTaskId = null;

    public function toggleTask(string $taskId): void
    {
        $this->expandedTaskId = $this->expandedTaskId === $taskId ? null : $taskId;
    }

    /**
     * Retry execution from a specific failed workflow step.
     */
    public function retryStep(string $stepId): void
    {
        $step = PlaybookStep::find($stepId);
        if (! $step || $step->experiment_id !== $this->experiment->id) {
            return;
        }

        try {
            app(RetryFromStepAction::class)->execute($this->experiment, $step);
        } catch (\Throwable $e) {
            Log::error('ExperimentTasksPanel: retryStep failed', [
                'experiment_id' => $this->experiment->id,
                'step_id' => $stepId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function render()
    {
        $isWorkflow = $this->experiment->hasWorkflow();

        if ($isWorkflow) {
            return $this->renderWorkflowTasks();
        }

        return $this->renderBuildTasks();
    }

    private function renderWorkflowTasks()
    {
        $steps = PlaybookStep::where('experiment_id', $this->experiment->id)
            ->orderBy('order')
            ->with(['agent', 'skill', 'crew'])
            ->get();

        $graph = $this->experiment->constraints['workflow_graph'] ?? null;
        $nodeConfigs = [];
        if ($graph) {
            foreach ($graph['nodes'] ?? [] as $node) {
                $nodeConfigs[$node['id']] = $node;
            }
        }

        $tasks = $steps->map(function ($step) use ($nodeConfigs) {
            $nodeConfig = $step->workflow_node_id ? ($nodeConfigs[$step->workflow_node_id] ?? null) : null;

            return (object) [
                'id' => $step->id,
                'name' => $this->resolveStepLabel($step, $nodeConfig),
                'description' => $nodeConfig['config']['description'] ?? null,
                'type' => $nodeConfig['type'] ?? 'agent',
                'status' => $step->status,
                'order' => $step->order,
                'provider' => $step->agent?->provider ?? null,
                'model' => null,
                'duration_ms' => $step->duration_ms,
                'cost_credits' => $step->cost_credits,
                'started_at' => $step->started_at,
                'completed_at' => $step->completed_at,
                'error' => $step->error_message,
                'output' => $step->output,
                'is_step' => true,
            ];
        });

        $total = $tasks->count();
        $completed = $tasks->where('status', 'completed')->count();
        $running = $tasks->where('status', 'running')->count();
        $failed = $tasks->where('status', 'failed')->count();
        $skipped = $tasks->where('status', 'skipped')->count();

        return view('livewire.experiments.experiment-tasks-panel', [
            'tasks' => $tasks,
            'total' => $total,
            'completed' => $completed,
            'running' => $running,
            'failed' => $failed,
            'skipped' => $skipped,
            'batchInfo' => null,
            'isWorkflow' => true,
            'workflowId' => $graph['workflow_id'] ?? null,
        ]);
    }

    private function renderBuildTasks()
    {
        $tasks = $this->experiment->tasks()->orderBy('sort_order')->get();

        $normalizedTasks = $tasks->map(function ($task) {
            return (object) [
                'id' => $task->id,
                'name' => $task->name,
                'description' => $task->description,
                'type' => $task->type,
                'status' => $task->status->value,
                'order' => $task->sort_order,
                'provider' => $task->provider,
                'model' => $task->model,
                'duration_ms' => $task->duration_ms,
                'cost_credits' => null,
                'started_at' => $task->started_at,
                'completed_at' => $task->completed_at,
                'error' => $task->error,
                'output' => $task->output_data,
                'is_step' => false,
            ];
        });

        $total = $normalizedTasks->count();
        $completed = $normalizedTasks->where('status', 'completed')->count();
        $running = $normalizedTasks->where('status', 'running')->count();
        $failed = $normalizedTasks->where('status', 'failed')->count();
        $skipped = $normalizedTasks->where('status', 'skipped')->count();

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
            'tasks' => $normalizedTasks,
            'total' => $total,
            'completed' => $completed,
            'running' => $running,
            'failed' => $failed,
            'skipped' => $skipped,
            'batchInfo' => $batchInfo,
            'isWorkflow' => false,
            'workflowId' => null,
        ]);
    }

    private function resolveStepLabel(PlaybookStep $step, ?array $nodeConfig): string
    {
        if ($nodeConfig) {
            $label = $nodeConfig['config']['label'] ?? $nodeConfig['label'] ?? null;
            if ($label && trim($label) !== '') {
                return trim($label);
            }
        }

        if ($step->agent) {
            return $step->agent->name;
        }

        if ($step->crew) {
            return $step->crew->name;
        }

        if ($step->skill) {
            return $step->skill->name;
        }

        return "Step {$step->order}";
    }
}
