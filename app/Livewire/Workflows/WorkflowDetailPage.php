<?php

namespace App\Livewire\Workflows;

use App\Domain\Workflow\Actions\DeleteWorkflowAction;
use App\Domain\Workflow\Actions\EstimateWorkflowCostAction;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;

class WorkflowDetailPage extends Component
{
    public Workflow $workflow;

    public function mount(Workflow $workflow): void
    {
        $this->workflow = $workflow->load(['nodes.agent', 'nodes.skill', 'edges', 'user']);
    }

    public function archive(): void
    {
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
        return view('livewire.workflows.workflow-detail-page', [
            'experiments' => $this->workflow->experiments()
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
            'agentNodes' => $this->workflow->agentNodes(),
        ])->layout('layouts.app', ['header' => $this->workflow->name]);
    }
}
