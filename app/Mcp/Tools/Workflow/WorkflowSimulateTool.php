<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowSimulator;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Dry-run a workflow graph through WorkflowSimulator and return the predicted
 * execution path / termination. WorkflowSimulator is pure: no real execution,
 * no LLM calls, no cost, no DB writes. Execution (non-control-flow) nodes are
 * auto-stubbed with empty output so the simulator can walk the whole graph,
 * mirroring the WorkflowSimulationPanel surface.
 */
#[IsReadOnly]
#[IsIdempotent]
class WorkflowSimulateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_simulate';

    protected string $description = 'Dry-run a workflow graph and return the predicted execution path and termination (no real execution, no LLM calls, no cost, no DB writes). Execution nodes are auto-stubbed with empty output.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID to simulate')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['workflow_id' => 'required|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['workflow_id']);

        if (! $workflow) {
            return $this->notFoundError('workflow');
        }

        $nodes = $workflow->nodes()->get()->keyBy('id');

        // Auto-stub every execution (non-control-flow) node with empty output.
        $simulator = new WorkflowSimulator;
        foreach ($nodes as $node) {
            if (! $node->type->isControlFlow() && $node->type !== WorkflowNodeType::End) {
                $simulator = $simulator->stub($node->id, []);
            }
        }

        $result = $simulator->run($workflow);

        return Response::text(json_encode([
            'workflow_id' => $workflow->id,
            'termination_status' => $result->terminationStatus,
            'termination_node_id' => $result->terminationNodeId,
            'executed_path' => collect($result->executedPath)
                ->map(fn (string $nodeId): array => [
                    'id' => $nodeId,
                    'label' => $nodes->get($nodeId)?->label ?? $nodeId,
                    'type' => $nodes->get($nodeId)?->type->value ?? 'unknown',
                ])
                ->toArray(),
        ]));
    }
}
