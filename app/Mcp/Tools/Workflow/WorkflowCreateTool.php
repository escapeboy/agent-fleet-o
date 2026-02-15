<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\CreateWorkflowAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowCreateTool extends Tool
{
    protected string $name = 'workflow_create';

    protected string $description = 'Create a new workflow with default start/end nodes. Use the workflow builder to add nodes and edges later.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Workflow name')
                ->required(),
            'description' => $schema->string()
                ->description('Workflow description'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        try {
            $workflow = app(CreateWorkflowAction::class)->execute(
                userId: auth()->id(),
                name: $validated['name'],
                description: $validated['description'] ?? null,
                nodes: [],
                edges: [],
                teamId: auth()->user()->current_team_id,
            );

            return Response::text(json_encode([
                'success' => true,
                'workflow_id' => $workflow->id,
                'name' => $workflow->name,
                'status' => $workflow->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
