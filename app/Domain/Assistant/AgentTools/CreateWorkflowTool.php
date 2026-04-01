<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Workflow\Actions\CreateWorkflowAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateWorkflowTool implements Tool
{
    public function name(): string
    {
        return 'create_workflow';
    }

    public function description(): string
    {
        return 'Create a blank workflow template with default start and end nodes';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Workflow name'),
            'description' => $schema->string()->description('Workflow description'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $workflow = app(CreateWorkflowAction::class)->execute(
                userId: auth()->id(),
                name: $request->get('name'),
                description: $request->get('description'),
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
    }
}
