<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\CreateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowDuplicateTool extends Tool
{
    protected string $name = 'workflow_duplicate';

    protected string $description = 'Duplicate an existing workflow. Creates a new draft workflow with all nodes and edges copied. Optionally override the name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID to duplicate')
                ->required(),
            'title' => $schema->string()
                ->description('Name for the new workflow (defaults to "<original name> (copy)")'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['workflow_id' => 'required|string']);

        $workflow = Workflow::with(['nodes', 'edges'])->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        try {
            $newName = $request->get('title') ?: $workflow->name.' (copy)';

            $newWorkflow = app(CreateWorkflowAction::class)->execute(
                userId: auth()->id(),
                name: $newName,
                description: $workflow->description,
                nodes: $workflow->nodes->map(fn ($n) => [
                    'type' => $n->type->value,
                    'label' => $n->label,
                    'agent_id' => $n->agent_id,
                    'skill_id' => $n->skill_id,
                    'position_x' => $n->position_x,
                    'position_y' => $n->position_y,
                    'config' => $n->config,
                ])->toArray(),
                edges: $workflow->edges->map(fn ($e) => [
                    'source_node_index' => $workflow->nodes->search(fn ($n) => $n->id === $e->source_node_id),
                    'target_node_index' => $workflow->nodes->search(fn ($n) => $n->id === $e->target_node_id),
                    'condition' => $e->condition,
                    'label' => $e->label,
                    'is_default' => $e->is_default,
                    'sort_order' => $e->sort_order,
                ])->toArray(),
                maxLoopIterations: $workflow->max_loop_iterations,
                teamId: app('mcp.team_id') ?? auth()->user()?->current_team_id,
            );

            return Response::text(json_encode([
                'success' => true,
                'id' => $newWorkflow->id,
                'name' => $newWorkflow->name,
                'status' => $newWorkflow->status->value,
                'node_count' => $newWorkflow->nodes()->count(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
