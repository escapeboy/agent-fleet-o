<?php

namespace App\Livewire\Workflows;

use App\Domain\Workflow\DTOs\SimulationResult;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\WorkflowSimulator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Dry-run a workflow graph through WorkflowSimulator (no real execution, no LLM
 * calls, no cost, no DB writes). Renders the predicted execution path.
 *
 * Execution nodes are auto-stubbed with empty output so the simulator can walk
 * the whole graph from the UI without asking the user for per-node stubs.
 */
class WorkflowSimulationPanel extends Component
{
    use AuthorizesRequests;

    public Workflow $workflow;

    /** @var array<int, array{id: string, label: string, type: string}> */
    public array $executedPath = [];

    public ?string $terminationStatus = null;

    public ?string $terminationNodeId = null;

    public bool $hasRun = false;

    public ?string $error = null;

    public function mount(Workflow $workflow): void
    {
        $this->workflow = $workflow;
    }

    public function simulate(): void
    {
        // Compute trigger — guard even though simulation is side-effect-free.
        $this->authorize('edit-content');

        $this->reset(['executedPath', 'terminationStatus', 'terminationNodeId', 'error']);
        $this->hasRun = true;

        $nodes = $this->workflow->nodes()->get()->keyBy('id');

        // Auto-stub every execution (non-control-flow) node with empty output.
        $simulator = new WorkflowSimulator;
        foreach ($nodes as $node) {
            if (! $node->type->isControlFlow() && $node->type !== WorkflowNodeType::End) {
                $simulator = $simulator->stub($node->id, []);
            }
        }

        $result = $simulator->run($this->workflow);

        $this->renderResult($result, $nodes);
    }

    /**
     * @param  Collection<string, WorkflowNode>  $nodes
     */
    private function renderResult(SimulationResult $result, $nodes): void
    {
        $this->terminationStatus = $result->terminationStatus;
        $this->terminationNodeId = $result->terminationNodeId;

        $this->executedPath = collect($result->executedPath)
            ->map(function (string $nodeId) use ($nodes): array {
                $node = $nodes->get($nodeId);

                return [
                    'id' => $nodeId,
                    'label' => $node?->label ?? $nodeId,
                    'type' => $node?->type->value ?? 'unknown',
                ];
            })
            ->all();

        if ($result->terminationStatus === 'error') {
            $this->error = 'Simulation could not start — the workflow has no Start node.';
        }
    }

    public function render()
    {
        return view('livewire.workflows.workflow-simulation-panel');
    }
}
