<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Actions\RetryFromStepAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class WorkflowProgressPanel extends Component
{
    public string $experimentId;

    public ?string $expandedNodeId = null;

    public function toggleNode(string $nodeId): void
    {
        $this->expandedNodeId = $this->expandedNodeId === $nodeId ? null : $nodeId;
    }

    /**
     * Get accumulated streaming output for a running step.
     */
    public function getStreamOutput(string $stepId): ?string
    {
        return app(StepOutputBroadcaster::class)->getAccumulatedOutput($stepId);
    }

    /**
     * Retry execution from a specific failed step.
     */
    public function retryStep(string $stepId): void
    {
        $step = PlaybookStep::find($stepId);
        if (! $step || $step->experiment_id !== $this->experimentId) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);
        if (! $experiment) {
            return;
        }

        try {
            app(RetryFromStepAction::class)->execute($experiment, $step);
        } catch (\Throwable $e) {
            Log::error('WorkflowProgressPanel: retryStep failed', [
                'experiment_id' => $this->experimentId,
                'step_id' => $stepId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function render()
    {
        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);
        $graph = $experiment?->constraints['workflow_graph'] ?? null;

        if (! $graph) {
            return view('livewire.experiments.workflow-progress-panel', [
                'graph' => null,
                'nodes' => collect(),
                'total' => 0,
                'completed' => 0,
                'running' => 0,
                'failed' => 0,
                'skipped' => 0,
                'workflowId' => null,
            ]);
        }

        $steps = PlaybookStep::where('experiment_id', $this->experimentId)
            ->whereNotNull('workflow_node_id')
            ->get()
            ->keyBy('workflow_node_id');

        $broadcaster = app(StepOutputBroadcaster::class);

        $nodes = collect($graph['nodes'])->map(function ($node) use ($steps, $broadcaster) {
            $step = $steps->get($node['id']);
            $node['step_id'] = $step?->id;
            $node['step_status'] = $step?->status ?? (in_array($node['type'], ['start', 'end']) ? 'system' : 'pending');
            $node['step_duration_ms'] = $step?->duration_ms;
            $node['step_error'] = $step?->error_message;
            $node['step_cost'] = $step?->cost_credits;
            $node['step_output'] = $step?->output;
            $node['step_started_at'] = $step?->started_at;

            // Fetch live streaming output for running steps
            $node['step_stream_output'] = null;
            if ($step && $step->status === 'running') {
                $node['step_stream_output'] = $broadcaster->getAccumulatedOutput($step->id);
            }

            return $node;
        });

        $total = $steps->count();
        $completed = $steps->where('status', 'completed')->count();
        $running = $steps->where('status', 'running')->count();
        $failed = $steps->where('status', 'failed')->count();
        $skipped = $steps->where('status', 'skipped')->count();

        return view('livewire.experiments.workflow-progress-panel', [
            'graph' => $graph,
            'nodes' => $nodes,
            'total' => $total,
            'completed' => $completed,
            'running' => $running,
            'failed' => $failed,
            'skipped' => $skipped,
            'workflowId' => $graph['workflow_id'] ?? null,
        ]);
    }
}
