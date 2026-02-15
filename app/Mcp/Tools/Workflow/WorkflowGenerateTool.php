<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\GenerateWorkflowFromPromptAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowGenerateTool extends Tool
{
    protected string $name = 'workflow_generate';

    protected string $description = 'Generate a workflow from a natural language prompt. Uses AI to decompose the description into a workflow graph with nodes and edges. Returns the created workflow with any validation warnings.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('Natural language description of the workflow to create')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'prompt' => 'required|string|min:10',
        ]);

        try {
            $result = app(GenerateWorkflowFromPromptAction::class)->execute(
                prompt: $validated['prompt'],
                userId: auth()->id(),
                teamId: auth()->user()?->currentTeam?->id,
            );

            $workflow = $result['workflow'];

            if (! $workflow) {
                return Response::error('Failed to generate workflow: '.implode(', ', $result['errors']));
            }

            $workflow->load(['nodes', 'edges']);

            return Response::text(json_encode([
                'success' => true,
                'workflow_id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'node_count' => $workflow->nodes->count(),
                'edge_count' => $workflow->edges->count(),
                'status' => $workflow->status->value,
                'validation_warnings' => $result['errors'],
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
