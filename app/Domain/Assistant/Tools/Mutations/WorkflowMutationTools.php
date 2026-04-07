<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Actions\CreateWorkflowAction;
use App\Domain\Workflow\Actions\GenerateWorkflowFromPromptAction;
use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Models\Workflow;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class WorkflowMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createSkill(),
            self::updateSkill(),
            self::createWorkflow(),
            self::saveWorkflowGraph(),
            self::generateWorkflow(),
            self::activateWorkflow(),
        ];
    }

    public static function createSkill(): PrismToolObject
    {
        return PrismTool::as('create_skill')
            ->for('Create a new reusable skill. Type must be one of: llm, connector, rule, hybrid.')
            ->withStringParameter('name', 'Skill name', required: true)
            ->withStringParameter('type', 'Skill type: llm, connector, rule, hybrid', required: true)
            ->withStringParameter('description', 'Skill description')
            ->withStringParameter('prompt_template', 'System prompt template for LLM-backed skills')
            ->using(function (string $name, string $type, ?string $description = null, ?string $prompt_template = null) {
                try {
                    $skillType = SkillType::tryFrom($type);
                    if (! $skillType) {
                        return json_encode(['error' => "Invalid skill type '{$type}'. Must be one of: llm, connector, rule, hybrid"]);
                    }

                    $skill = app(CreateSkillAction::class)->execute(
                        teamId: auth()->user()->current_team_id,
                        name: $name,
                        type: $skillType,
                        description: $description ?? '',
                        systemPrompt: $prompt_template,
                        createdBy: auth()->id(),
                    );

                    return json_encode([
                        'success' => true,
                        'skill_id' => $skill->id,
                        'name' => $skill->name,
                        'status' => $skill->status->value,
                        'url' => route('skills.show', $skill),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function updateSkill(): PrismToolObject
    {
        return PrismTool::as('update_skill')
            ->for('Update an existing skill (name, description, or prompt template)')
            ->withStringParameter('skill_id', 'The skill UUID', required: true)
            ->withStringParameter('name', 'New skill name')
            ->withStringParameter('description', 'New skill description')
            ->withStringParameter('prompt_template', 'New system prompt template')
            ->using(function (string $skill_id, ?string $name = null, ?string $description = null, ?string $prompt_template = null) {
                $skill = Skill::find($skill_id);
                if (! $skill) {
                    return json_encode(['error' => 'Skill not found']);
                }

                try {
                    $attributes = array_filter([
                        'name' => $name,
                        'description' => $description,
                        'system_prompt' => $prompt_template,
                    ], fn ($v) => $v !== null);

                    if (empty($attributes)) {
                        return json_encode(['error' => 'No attributes provided to update']);
                    }

                    $skill = app(UpdateSkillAction::class)->execute(
                        skill: $skill,
                        attributes: $attributes,
                        updatedBy: auth()->id(),
                    );

                    return json_encode([
                        'success' => true,
                        'skill_id' => $skill->id,
                        'name' => $skill->name,
                        'version' => $skill->current_version,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function createWorkflow(): PrismToolObject
    {
        return PrismTool::as('create_workflow')
            ->for('Create a blank workflow template with default start and end nodes')
            ->withStringParameter('name', 'Workflow name', required: true)
            ->withStringParameter('description', 'Workflow description')
            ->using(function (string $name, ?string $description = null) {
                try {
                    $workflow = app(CreateWorkflowAction::class)->execute(
                        userId: auth()->id(),
                        name: $name,
                        description: $description,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $workflow->id,
                        'name' => $workflow->name,
                        'status' => $workflow->status->value,
                        'url' => route('workflows.show', $workflow),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function saveWorkflowGraph(): PrismToolObject
    {
        return PrismTool::as('save_workflow_graph')
            ->for('Save or replace the node/edge graph for an existing workflow. Use after create_workflow to add nodes and connections, or to fix a generated workflow. Nodes JSON: [{type,label,agent_id?,position_x?,position_y?,config?}]. Edges JSON: [{source_node_index,target_node_index,condition?,is_default?}]. Node types: start, end, agent, conditional, human_task, switch.')
            ->withStringParameter('workflow_id', 'UUID of the workflow to update', required: true)
            ->withStringParameter('nodes', 'JSON array of node objects. Example: [{"type":"start","label":"Start"},{"type":"agent","label":"Researcher","agent_id":"<uuid>"},{"type":"end","label":"End"}]', required: true)
            ->withStringParameter('edges', 'JSON array of edge objects using 0-based node indices. Example: [{"source_node_index":0,"target_node_index":1},{"source_node_index":1,"target_node_index":2}]', required: true)
            ->using(function (string $workflow_id, string $nodes, string $edges) {
                $workflow = Workflow::find($workflow_id);
                if (! $workflow) {
                    return json_encode(['error' => "Workflow not found: {$workflow_id}"]);
                }

                $nodesArray = json_decode($nodes, true);
                $edgesArray = json_decode($edges, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json_encode(['error' => 'Invalid JSON: '.json_last_error_msg()]);
                }

                // Remap source_node_index/target_node_index → source_node_id/target_node_id
                // UpdateWorkflowAction keys nodes by array index when no id field is present
                $remappedEdges = array_map(fn ($e) => array_merge($e, [
                    'source_node_id' => $e['source_node_index'] ?? $e['source_node_id'] ?? null,
                    'target_node_id' => $e['target_node_index'] ?? $e['target_node_id'] ?? null,
                ]), $edgesArray ?? []);

                try {
                    $updated = app(UpdateWorkflowAction::class)->execute(
                        workflow: $workflow,
                        nodes: $nodesArray ?? [],
                        edges: $remappedEdges,
                    );

                    $updated->load(['nodes', 'edges']);

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $updated->id,
                        'name' => $updated->name,
                        'node_count' => $updated->nodes->count(),
                        'edge_count' => $updated->edges->count(),
                        'status' => $updated->status->value,
                        'url' => route('workflows.show', $updated),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function generateWorkflow(): PrismToolObject
    {
        return PrismTool::as('generate_workflow')
            ->for('Generate a full workflow DAG from a natural language description. Uses AI to decompose the prompt into nodes and edges. Note: this calls an LLM internally and incurs additional cost.')
            ->withStringParameter('prompt', 'Natural language description of the workflow to generate (min 10 characters)', required: true)
            ->using(function (string $prompt) {
                try {
                    $result = app(GenerateWorkflowFromPromptAction::class)->execute(
                        prompt: $prompt,
                        userId: auth()->id(),
                        teamId: auth()->user()->current_team_id,
                    );

                    $workflow = $result['workflow'];

                    if (! $workflow) {
                        return json_encode(['error' => 'Failed to generate workflow: '.implode(', ', $result['errors'])]);
                    }

                    $workflow->load(['nodes', 'edges']);

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $workflow->id,
                        'name' => $workflow->name,
                        'description' => $workflow->description,
                        'node_count' => $workflow->nodes->count(),
                        'edge_count' => $workflow->edges->count(),
                        'status' => $workflow->status->value,
                        'validation_warnings' => $result['errors'],
                        'url' => route('workflows.show', $workflow),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function activateWorkflow(): PrismToolObject
    {
        return PrismTool::as('activate_workflow')
            ->for('Validate and activate a workflow so it can be used in experiments and projects. The graph must have valid start/end nodes.')
            ->withStringParameter('workflow_id', 'The workflow UUID', required: true)
            ->using(function (string $workflow_id) {
                $workflow = Workflow::find($workflow_id);
                if (! $workflow) {
                    return json_encode(['error' => 'Workflow not found']);
                }

                try {
                    $result = app(ValidateWorkflowGraphAction::class)->execute($workflow, activateIfValid: true);

                    if (! $result['valid']) {
                        return json_encode(['error' => 'Workflow graph is invalid: '.implode(', ', $result['errors'])]);
                    }

                    $workflow->refresh();

                    return json_encode([
                        'success' => true,
                        'workflow_id' => $workflow->id,
                        'name' => $workflow->name,
                        'status' => $workflow->status->value,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
