<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Actions\RetryFromStepAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkflowProgressPanel extends Component
{
    public string $experimentId;

    public ?string $expandedNodeId = null;

    /**
     * Verify the experiment belongs to the current team. TeamScope returns
     * null for cross-tenant IDs → 404. Without this guard, PlaybookStep
     * (no BelongsToTeam trait) leaks via experiment_id queries below.
     */
    public function mount(string $experimentId): void
    {
        Experiment::query()->findOrFail($experimentId);
        $this->experimentId = $experimentId;
    }

    /**
     * Real-time node states pushed via WorkflowNodeUpdated broadcast event.
     * Keyed by workflow_node_id. Each entry: {status, durationMs, cost, outputPreview}.
     *
     * @var array<string, array{status: string, durationMs: int, cost: float, outputPreview: string}>
     */
    public array $nodeStates = [];

    /**
     * Listen for WorkflowNodeUpdated broadcast events via Laravel Echo.
     * Livewire 4 resolves {experimentId} from the component property automatically.
     */
    #[On('echo-private:experiment.{experimentId},WorkflowNodeUpdated')]
    public function handleNodeUpdated(array $data): void
    {
        $nodeId = $data['nodeId'] ?? null;

        if (! $nodeId) {
            return;
        }

        $this->nodeStates[$nodeId] = [
            'status' => $data['status'] ?? 'pending',
            'durationMs' => $data['durationMs'] ?? 0,
            'cost' => $data['cost'] ?? 0.0,
            'outputPreview' => $data['outputPreview'] ?? '',
        ];
    }

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
        // Re-verify ownership: $experimentId is bound at mount but a hostile
        // client could try to swap properties on subsequent updates.
        $experiment = Experiment::query()->find($this->experimentId);
        if (! $experiment) {
            return;
        }

        $step = PlaybookStep::find($stepId);
        if (! $step || $step->experiment_id !== $experiment->id) {
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
        $experiment = Experiment::query()->find($this->experimentId);
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
            $node['step_status'] = $step->status ?? (in_array($node['type'], ['start', 'end']) ? 'system' : 'pending');
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
