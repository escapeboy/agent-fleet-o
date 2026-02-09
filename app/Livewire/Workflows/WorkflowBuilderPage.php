<?php

namespace App\Livewire\Workflows;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Actions\CreateWorkflowAction;
use App\Domain\Workflow\Actions\EstimateWorkflowCostAction;
use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;

class WorkflowBuilderPage extends Component
{
    public ?string $workflowId = null;

    // Workflow metadata
    public string $name = '';
    public string $description = '';
    public int $maxLoopIterations = 10;

    // Graph state (synced with Alpine.js canvas)
    public array $nodes = [];
    public array $edges = [];

    // Validation
    public array $validationErrors = [];
    public bool $isValid = false;

    // Available resources for node config
    public array $availableAgents = [];
    public array $availableSkills = [];
    public array $availableCrews = [];

    public function mount(?Workflow $workflow = null): void
    {
        if ($workflow && $workflow->exists) {
            $this->workflowId = $workflow->id;
            $this->name = $workflow->name;
            $this->description = $workflow->description ?? '';
            $this->maxLoopIterations = $workflow->max_loop_iterations;

            $workflow->load(['nodes.agent', 'nodes.skill', 'edges']);

            $this->nodes = $workflow->nodes->map(fn ($node) => [
                'id' => $node->id,
                'type' => $node->type->value,
                'label' => $node->label,
                'agent_id' => $node->agent_id,
                'skill_id' => $node->skill_id,
                'crew_id' => $node->crew_id,
                'config' => $node->config ?? [],
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
                'order' => $node->order,
            ])->toArray();

            $this->edges = $workflow->edges->map(fn ($edge) => [
                'id' => $edge->id,
                'source_node_id' => $edge->source_node_id,
                'target_node_id' => $edge->target_node_id,
                'condition' => $edge->condition,
                'label' => $edge->label,
                'is_default' => $edge->is_default,
                'sort_order' => $edge->sort_order,
            ])->toArray();
        } else {
            // Initialize with start and end nodes
            $this->nodes = [
                [
                    'id' => 'node-start',
                    'type' => 'start',
                    'label' => 'Start',
                    'agent_id' => null,
                    'skill_id' => null,
                    'config' => [],
                    'position_x' => 250,
                    'position_y' => 50,
                    'order' => 0,
                ],
                [
                    'id' => 'node-end',
                    'type' => 'end',
                    'label' => 'End',
                    'agent_id' => null,
                    'skill_id' => null,
                    'config' => [],
                    'position_x' => 250,
                    'position_y' => 400,
                    'order' => 99,
                ],
            ];
        }

        $this->availableAgents = Agent::select('id', 'name', 'status')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->toArray();

        $this->availableSkills = Skill::select('id', 'name', 'type', 'status')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->toArray();

        $this->availableCrews = Crew::select('id', 'name', 'status', 'process_type')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function saveGraph(array $nodes, array $edges): void
    {
        $this->nodes = $nodes;
        $this->edges = $edges;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'maxLoopIterations' => 'required|integer|min:1|max:100',
        ]);

        try {
            if ($this->workflowId) {
                $workflow = Workflow::findOrFail($this->workflowId);

                app(UpdateWorkflowAction::class)->execute(
                    workflow: $workflow,
                    name: $this->name,
                    description: $this->description,
                    maxLoopIterations: $this->maxLoopIterations,
                    nodes: $this->nodes,
                    edges: $this->edges,
                );

                session()->flash('success', 'Workflow updated.');
            } else {
                $team = auth()->user()->currentTeam;

                $workflow = app(CreateWorkflowAction::class)->execute(
                    userId: auth()->id(),
                    teamId: $team?->id,
                    name: $this->name,
                    description: $this->description,
                    maxLoopIterations: $this->maxLoopIterations,
                    nodes: $this->nodes,
                    edges: $this->edges,
                );

                $this->workflowId = $workflow->id;
                session()->flash('success', 'Workflow created.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function validateGraph(): void
    {
        if (! $this->workflowId) {
            $this->save();

            if (! $this->workflowId) {
                return;
            }
        }

        $workflow = Workflow::findOrFail($this->workflowId);
        $result = app(ValidateWorkflowGraphAction::class)->execute($workflow);

        $this->validationErrors = $result['errors'];
        $this->isValid = $result['valid'];

        if ($result['valid']) {
            app(EstimateWorkflowCostAction::class)->execute($workflow);
            session()->flash('success', 'Workflow graph is valid!');
        }
    }

    public function activate(): void
    {
        if (! $this->workflowId) {
            return;
        }

        $this->save();

        $workflow = Workflow::findOrFail($this->workflowId);
        $result = app(ValidateWorkflowGraphAction::class)->execute($workflow, activateIfValid: true);

        $this->validationErrors = $result['errors'];
        $this->isValid = $result['valid'];

        if ($result['activated']) {
            app(EstimateWorkflowCostAction::class)->execute($workflow);
            session()->flash('success', 'Workflow activated and ready to use!');
            $this->redirectRoute('workflows.show', $workflow);
        } elseif (! $result['valid']) {
            session()->flash('error', 'Cannot activate: fix validation errors first.');
        }
    }

    public function render()
    {
        return view('livewire.workflows.workflow-builder-page', [
            'nodeTypes' => WorkflowNodeType::cases(),
        ])->layout('layouts.app', ['header' => $this->workflowId ? 'Edit Workflow' : 'Create Workflow']);
    }
}
