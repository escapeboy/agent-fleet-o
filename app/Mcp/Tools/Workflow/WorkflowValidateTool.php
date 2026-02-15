<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowValidateTool extends Tool
{
    protected string $name = 'workflow_validate';

    protected string $description = 'Validate a workflow graph structure. Returns whether the graph is valid and any errors found.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['workflow_id' => 'required|string']);

        $workflow = Workflow::find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        $action = app(ValidateWorkflowGraphAction::class);
        $result = $action->execute($workflow);

        return Response::text(json_encode([
            'workflow_id' => $workflow->id,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'activated' => $result['activated'],
        ]));
    }
}
