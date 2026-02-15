<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowUpdateTool extends Tool
{
    protected string $name = 'workflow_update';

    protected string $description = 'Update an existing workflow metadata. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New workflow name'),
            'description' => $schema->string()
                ->description('New workflow description'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $workflow = Workflow::find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        $hasUpdates = ($validated['name'] ?? null) !== null || ($validated['description'] ?? null) !== null;

        if (! $hasUpdates) {
            return Response::error('No fields to update. Provide at least one of: name, description.');
        }

        try {
            $result = app(UpdateWorkflowAction::class)->execute(
                workflow: $workflow,
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'workflow_id' => $result->id,
                'name' => $result->name,
                'updated_fields' => array_keys(array_filter([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                ], fn ($v) => $v !== null)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
