<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Workflow\Actions\GenerateWorkflowFromPromptAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GenerateWorkflowTool implements Tool
{
    public function name(): string
    {
        return 'generate_workflow';
    }

    public function description(): string
    {
        return 'Generate a full workflow DAG from a natural language description. Uses AI to decompose the prompt into nodes and edges. Note: this calls an LLM internally and incurs additional cost.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->required()->description('Natural language description of the workflow to generate (min 10 characters)'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $result = app(GenerateWorkflowFromPromptAction::class)->execute(
                prompt: $request->get('prompt'),
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
    }
}
