<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
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
            'checkpoint_mode' => $schema->string()
                ->description('Checkpoint durability mode: sync (safest), async (Redis buffer), exit (in-memory). Default: sync')
                ->enum(['sync', 'async', 'exit']),
            'budget_cap_credits' => $schema->integer()
                ->description('Maximum credits per execution. Set to 0 to remove the cap.'),
            'observability_config' => $schema->object()
                ->description('Observability provider config. Schema: {"provider":"langfuse|langsmith|none","enabled":true,"config":{"public_key":"...","secret_key":"...","host":"https://cloud.langfuse.com"}}'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'checkpoint_mode' => 'nullable|string|in:sync,async,exit',
            'budget_cap_credits' => 'nullable|integer|min:0',
            'observability_config' => 'nullable|array',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $workflow = Workflow::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        $hasUpdates = ($validated['name'] ?? null) !== null
            || ($validated['description'] ?? null) !== null
            || ($validated['checkpoint_mode'] ?? null) !== null
            || array_key_exists('budget_cap_credits', $validated)
            || ($validated['observability_config'] ?? null) !== null;

        if (! $hasUpdates) {
            return Response::error('No fields to update. Provide at least one of: name, description, checkpoint_mode.');
        }

        try {
            $settings = null;
            if (! empty($validated['checkpoint_mode'])) {
                $settings = ['checkpoint_mode' => $validated['checkpoint_mode']];
            }

            $budgetCap = $validated['budget_cap_credits'] ?? null;

            // Persist observability_config directly (not handled by UpdateWorkflowAction)
            if (! empty($validated['observability_config'])) {
                $workflow->observability_config = $validated['observability_config'];
                $workflow->save();
            }

            $result = app(UpdateWorkflowAction::class)->execute(
                workflow: $workflow,
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
                settings: $settings,
                budgetCapCredits: $budgetCap === null ? null : ($budgetCap === 0 ? null : $budgetCap),
                clearBudgetCap: $budgetCap === 0,
            );

            return Response::text(json_encode([
                'success' => true,
                'workflow_id' => $result->id,
                'name' => $result->name,
                'updated_fields' => array_keys(array_filter([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'checkpoint_mode' => $validated['checkpoint_mode'] ?? null,
                ], fn ($v) => $v !== null)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
