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
use Livewire\Attributes\On;
use Livewire\Component;

class WorkflowBuilderPage extends Component
{
    public ?string $workflowId = null;

    // Workflow metadata
    public string $name = '';

    public string $description = '';

    public int $maxLoopIterations = 10;

    public string $checkpointMode = 'sync';

    public ?int $budgetCapCredits = null;

    // Graph state (synced with Alpine.js canvas)
    public array $nodes = [];

    public array $edges = [];

    // Validation
    public array $validationErrors = [];

    public array $validationWarnings = [];

    public bool $isValid = false;

    // Available resources for node config
    public array $availableAgents = [];

    public array $availableSkills = [];

    public array $availableCrews = [];

    public array $availableWorkflows = [];

    public function mount(?Workflow $workflow = null): void
    {
        if ($workflow && $workflow->exists) {
            $this->workflowId = $workflow->id;
            $this->name = $workflow->name;
            $this->description = $workflow->description ?? '';
            $this->maxLoopIterations = $workflow->max_loop_iterations;
            $this->checkpointMode = $workflow->settings['checkpoint_mode'] ?? 'sync';
            $this->budgetCapCredits = $workflow->budget_cap_credits;

            $workflow->load(['nodes.agent', 'nodes.skill', 'edges']);

            $this->nodes = $workflow->nodes->map(fn ($node) => [
                'id' => $node->id,
                'type' => $node->type->value,
                'label' => $node->label,
                'agent_id' => $node->agent_id,
                'skill_id' => $node->skill_id,
                'crew_id' => $node->crew_id,
                'sub_workflow_id' => $node->sub_workflow_id,
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

        // Exclude the current workflow to prevent self-referential sub-workflows
        $this->availableWorkflows = Workflow::select('id', 'name', 'status')
            ->where('status', 'active')
            ->when($this->workflowId, fn ($q) => $q->where('id', '!=', $this->workflowId))
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function saveGraph(array $nodes, array $edges): void
    {
        $this->nodes = $nodes;
        $this->edges = $edges;
    }

    public function save(bool $flash = true): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'maxLoopIterations' => 'required|integer|min:1|max:100',
            'checkpointMode' => 'required|in:sync,async,exit',
            'budgetCapCredits' => 'nullable|integer|min:1',
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
                    settings: ['checkpoint_mode' => $this->checkpointMode],
                    budgetCapCredits: $this->budgetCapCredits,
                    clearBudgetCap: $this->budgetCapCredits === null,
                );

                if ($flash) {
                    session()->flash('success', 'Workflow updated.');
                }
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
                    settings: ['checkpoint_mode' => $this->checkpointMode],
                    budgetCapCredits: $this->budgetCapCredits,
                );

                $this->workflowId = $workflow->id;
                if ($flash) {
                    session()->flash('success', 'Workflow created.');
                }
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
        $this->validationWarnings = $result['warnings'] ?? [];
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

        $this->save(flash: false);

        $workflow = Workflow::findOrFail($this->workflowId);
        $result = app(ValidateWorkflowGraphAction::class)->execute($workflow, activateIfValid: true);

        $this->validationErrors = $result['errors'];
        $this->validationWarnings = $result['warnings'] ?? [];
        $this->isValid = $result['valid'];

        if ($result['activated']) {
            app(EstimateWorkflowCostAction::class)->execute($workflow);
            session()->flash('success', 'Workflow activated and ready to use!');
            $this->redirectRoute('workflows.show', $workflow);
        } elseif (! $result['valid']) {
            session()->flash('error', 'Cannot activate: fix validation errors first.');
        } elseif (! $workflow->isDraft()) {
            session()->flash('info', 'Workflow is already active.');
            $this->redirectRoute('workflows.show', $workflow);
        }
    }

    /**
     * Handle a workflow file imported via the PWA File Handling API.
     * Dispatched by pwa-features.js when the user opens a .json/.yaml file with FleetQ.
     */
    #[On('file-imported')]
    public function fileImported(string $content, string $name, string $type): void
    {
        try {
            $data = match (true) {
                str_ends_with($name, '.json') => json_decode($content, true, flags: JSON_THROW_ON_ERROR),
                default => [], // YAML parsing requires a package; emit an error for now
            };

            if (! is_array($data)) {
                $this->addError('import', 'Invalid file format.');

                return;
            }

            // Populate graph from imported definition
            if (! empty($data['name'])) {
                $this->name = $data['name'];
            }
            if (! empty($data['description'])) {
                $this->description = $data['description'];
            }
            if (! empty($data['nodes']) && is_array($data['nodes'])) {
                $this->nodes = $this->sanitizeImportedNodes($data['nodes']);
            }
            if (! empty($data['edges']) && is_array($data['edges'])) {
                $nodeIds = array_column($this->nodes, 'id');
                $this->edges = $this->sanitizeImportedEdges($data['edges'], $nodeIds);
            }

            session()->flash('success', "Workflow imported from {$name}. Review and save when ready.");
        } catch (\JsonException $e) {
            $this->addError('import', 'Could not parse file: '.$e->getMessage());
        }
    }

    /**
     * Sanitize nodes from an imported JSON file.
     * - Strips invalid node types.
     * - Validates entity UUID references against the current team's resources so
     *   a crafted import file cannot reference another team's agents/crews/skills.
     */
    private function sanitizeImportedNodes(array $nodes): array
    {
        $validTypes = array_map(fn ($t) => $t->value, WorkflowNodeType::cases());
        $validAgentIds = array_column($this->availableAgents, 'id');
        $validCrewIds = array_column($this->availableCrews, 'id');
        $validSkillIds = array_column($this->availableSkills, 'id');
        $validWorkflowIds = array_column($this->availableWorkflows, 'id');

        $sanitized = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? null;
            if (! in_array($type, $validTypes, true)) {
                continue;
            }
            $sanitized[] = [
                'id' => isset($node['id']) && is_string($node['id']) ? $node['id'] : 'node-'.uniqid(),
                'type' => $type,
                'label' => isset($node['label']) && is_string($node['label']) ? mb_substr($node['label'], 0, 255) : $type,
                'agent_id' => isset($node['agent_id']) && in_array($node['agent_id'], $validAgentIds, true) ? $node['agent_id'] : null,
                'skill_id' => isset($node['skill_id']) && in_array($node['skill_id'], $validSkillIds, true) ? $node['skill_id'] : null,
                'crew_id' => isset($node['crew_id']) && in_array($node['crew_id'], $validCrewIds, true) ? $node['crew_id'] : null,
                'sub_workflow_id' => isset($node['sub_workflow_id']) && in_array($node['sub_workflow_id'], $validWorkflowIds, true) ? $node['sub_workflow_id'] : null,
                'config' => isset($node['config']) && is_array($node['config']) ? $node['config'] : [],
                'position_x' => isset($node['position_x']) ? (float) $node['position_x'] : 0.0,
                'position_y' => isset($node['position_y']) ? (float) $node['position_y'] : 0.0,
                'order' => isset($node['order']) ? (int) $node['order'] : 0,
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitize edges from an imported JSON file.
     * Strips edges whose source or target node IDs are not present in the sanitized node list.
     */
    private function sanitizeImportedEdges(array $edges, array $nodeIds): array
    {
        $sanitized = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = $edge['source_node_id'] ?? null;
            $target = $edge['target_node_id'] ?? null;
            if (! in_array($source, $nodeIds, true) || ! in_array($target, $nodeIds, true)) {
                continue;
            }
            $sanitized[] = [
                'id' => isset($edge['id']) && is_string($edge['id']) ? $edge['id'] : 'edge-'.uniqid(),
                'source_node_id' => $source,
                'target_node_id' => $target,
                'condition' => isset($edge['condition']) && is_string($edge['condition']) ? $edge['condition'] : null,
                'label' => isset($edge['label']) && is_string($edge['label']) ? mb_substr($edge['label'], 0, 255) : null,
                'is_default' => ! empty($edge['is_default']),
                'sort_order' => isset($edge['sort_order']) ? (int) $edge['sort_order'] : 0,
            ];
        }

        return $sanitized;
    }

    public function render()
    {
        return view('livewire.workflows.workflow-builder-page', [
            'nodeTypes' => WorkflowNodeType::cases(),
            'availableWorkflows' => $this->availableWorkflows,
        ])->layout('layouts.app', ['header' => $this->workflowId ? 'Edit Workflow' : 'Create Workflow']);
    }
}
