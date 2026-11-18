<?php

namespace App\Livewire\Workflows;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Actions\DeleteWorkflowAction;
use App\Domain\Workflow\Actions\EnableWorkflowMcpGatewayAction;
use App\Domain\Workflow\Actions\EstimateWorkflowCostAction;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class WorkflowDetailPage extends Component
{
    use AuthorizesRequests;

    public Workflow $workflow;

    public string $mcpToolName = '';

    public string $mcpExecutionMode = 'async';

    public function mount(Workflow $workflow): void
    {
        $this->workflow = $workflow->load(['nodes.agent', 'nodes.skill', 'edges', 'user']);

        $toolName = $this->workflow->getAttribute('mcp_tool_name');
        $mode = $this->workflow->getAttribute('mcp_execution_mode');

        $this->mcpToolName = is_string($toolName) && $toolName !== ''
            ? $toolName
            : substr(Str::snake((string) $this->workflow->getAttribute('name')), 0, 64);
        $this->mcpExecutionMode = is_string($mode) && $mode !== '' ? $mode : 'async';
    }

    public function enableMcpGateway(): void
    {
        $this->authorize('edit-content');

        try {
            app(EnableWorkflowMcpGatewayAction::class)->execute(
                $this->workflow,
                $this->mcpToolName,
                $this->mcpExecutionMode,
            );
        } catch (ValidationException $e) {
            $errors = $e->errors();

            throw ValidationException::withMessages(array_filter([
                'mcpToolName' => $errors['tool_name'] ?? null,
                'mcpExecutionMode' => $errors['mcp_execution_mode'] ?? null,
            ]));
        }

        $this->workflow->refresh();
        session()->flash('success', "Workflow exposed as MCP tool '{$this->mcpToolName}'.");
    }

    public function disableMcpGateway(): void
    {
        $this->authorize('edit-content');

        $this->workflow->update([
            'mcp_exposed' => false,
            'mcp_tool_name' => null,
        ]);

        $this->workflow->refresh();
        session()->flash('success', 'MCP tool disabled.');
    }

    public function archive(): void
    {
        $this->authorize('edit-content');

        app(DeleteWorkflowAction::class)->execute($this->workflow);

        session()->flash('success', 'Workflow archived.');
        $this->redirectRoute('workflows.index');
    }

    public function recalculateCost(): void
    {
        $cost = app(EstimateWorkflowCostAction::class)->execute($this->workflow);
        $this->workflow->refresh();
        session()->flash('success', "Cost recalculated: {$cost} credits.");
    }

    public function duplicate(): void
    {
        $newWorkflow = $this->workflow->replicate(['id', 'slug', 'created_at', 'updated_at']);
        $newWorkflow->name = $this->workflow->name.' (Copy)';
        $newWorkflow->slug = $this->workflow->slug.'-copy-'.substr(uniqid(), -4);
        $newWorkflow->status = WorkflowStatus::Draft;
        $newWorkflow->version = 1;
        $newWorkflow->save();

        // Duplicate nodes
        $nodeIdMap = [];
        foreach ($this->workflow->nodes as $node) {
            $newNode = $node->replicate(['id', 'workflow_id', 'created_at', 'updated_at']);
            $newNode->workflow_id = $newWorkflow->id;
            $newNode->save();
            $nodeIdMap[$node->id] = $newNode->id;
        }

        // Duplicate edges with remapped node IDs
        foreach ($this->workflow->edges as $edge) {
            $newEdge = $edge->replicate(['id', 'workflow_id', 'created_at', 'updated_at']);
            $newEdge->workflow_id = $newWorkflow->id;
            $newEdge->source_node_id = $nodeIdMap[$edge->source_node_id] ?? $edge->source_node_id;
            $newEdge->target_node_id = $nodeIdMap[$edge->target_node_id] ?? $edge->target_node_id;
            $newEdge->save();
        }

        session()->flash('success', 'Workflow duplicated.');
        $this->redirectRoute('workflows.edit', $newWorkflow);
    }

    public function render()
    {
        $experiments = $this->workflow->experiments()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Find the most recent running/recent experiment to show live status overlay
        $activeExperiment = $experiments->first(
            fn (Experiment $e) => in_array($e->status, [
                ExperimentStatus::Executing,
                ExperimentStatus::Building,
                ExperimentStatus::Planning,
            ]),
        ) ?? $experiments->first();

        $stepStatuses = [];
        $hasRunningSteps = false;

        if ($activeExperiment) {
            $steps = PlaybookStep::where('experiment_id', $activeExperiment->id)
                ->whereNotNull('workflow_node_id')
                ->get();

            foreach ($steps as $step) {
                $stepStatuses[$step->workflow_node_id] = [
                    'status' => $step->status,
                    'duration' => $step->duration_ms,
                    'error' => $step->error_message,
                    'cost' => $step->cost_credits,
                ];
            }

            $hasRunningSteps = $steps->where('status', 'running')->isNotEmpty();
        }

        return view('livewire.workflows.workflow-detail-page', [
            'experiments' => $experiments,
            'agentNodes' => $this->workflow->agentNodes(),
            'activeExperiment' => $activeExperiment,
            'stepStatuses' => $stepStatuses,
            'hasRunningSteps' => $hasRunningSteps,
        ])->layout('layouts.app', ['header' => $this->workflow->name]);
    }
}
