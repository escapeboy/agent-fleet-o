<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Livewire\Component;

class WorkflowProgressPanel extends Component
{
    public string $experimentId;

    public ?string $expandedNodeId = null;

    public function toggleNode(string $nodeId): void
    {
        $this->expandedNodeId = $this->expandedNodeId === $nodeId ? null : $nodeId;
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
                'workflowId' => null,
            ]);
        }

        $steps = PlaybookStep::where('experiment_id', $this->experimentId)
            ->whereNotNull('workflow_node_id')
            ->get()
            ->keyBy('workflow_node_id');

        $nodes = collect($graph['nodes'])->map(function ($node) use ($steps) {
            $step = $steps->get($node['id']);
            $node['step_status'] = $step?->status ?? (in_array($node['type'], ['start', 'end']) ? 'system' : 'pending');
            $node['step_duration_ms'] = $step?->duration_ms;
            $node['step_error'] = $step?->error_message;
            $node['step_cost'] = $step?->cost_credits;
            $node['step_output'] = $step?->output;

            return $node;
        });

        $total = $steps->count();
        $completed = $steps->where('status', 'completed')->count();
        $running = $steps->where('status', 'running')->count();
        $failed = $steps->where('status', 'failed')->count();

        return view('livewire.experiments.workflow-progress-panel', [
            'graph' => $graph,
            'nodes' => $nodes,
            'total' => $total,
            'completed' => $completed,
            'running' => $running,
            'failed' => $failed,
            'workflowId' => $graph['workflow_id'] ?? null,
        ]);
    }
}
