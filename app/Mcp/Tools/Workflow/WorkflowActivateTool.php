<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowActivateTool extends Tool
{
    protected string $name = 'workflow_activate';

    protected string $description = 'Validate and activate a workflow. The workflow graph must be valid (has start/end nodes, no orphaned nodes) before it can be used in experiments.';

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

        try {
            $result = app(ValidateWorkflowGraphAction::class)->execute($workflow, activateIfValid: true);

            if (! $result['valid']) {
                return Response::error('Workflow graph is invalid: '.implode(', ', $result['errors']));
            }

            $workflow->refresh();

            return Response::text(json_encode([
                'success' => true,
                'message' => 'Workflow activated.',
                'id' => $workflow->id,
                'name' => $workflow->name,
                'status' => $workflow->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
