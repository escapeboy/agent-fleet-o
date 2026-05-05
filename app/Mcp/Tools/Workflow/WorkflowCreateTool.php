<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\CreateWorkflowAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowCreateTool extends Tool
{
    use HasStructuredErrors;

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
            'checkpoint_mode' => $schema->string()
                ->description('Checkpoint durability mode: sync (safest, DB write per step), async (Redis buffer + background flush), exit (in-memory, flushed on completion). Default: sync')
                ->enum(['sync', 'async', 'exit']),
            'budget_cap_credits' => $schema->integer()
                ->description('Maximum credits this workflow may consume per execution. Propagated to each experiment created from this workflow. Omit for no cap.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'checkpoint_mode' => 'nullable|string|in:sync,async,exit',
            'budget_cap_credits' => 'nullable|integer|min:1',
        ]);
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            $settings = [];
            if (! empty($validated['checkpoint_mode'])) {
                $settings['checkpoint_mode'] = $validated['checkpoint_mode'];
            }

            $workflow = app(CreateWorkflowAction::class)->execute(
                userId: auth()->id(),
                name: $validated['name'],
                description: $validated['description'] ?? null,
                nodes: [],
                edges: [],
                teamId: $teamId,
                settings: $settings,
                budgetCapCredits: $validated['budget_cap_credits'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'workflow_id' => $workflow->id,
                'name' => $workflow->name,
                'status' => $workflow->status->value,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
