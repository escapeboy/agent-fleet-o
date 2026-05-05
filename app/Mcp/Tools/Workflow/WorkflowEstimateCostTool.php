<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\EstimateWorkflowCostAction;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowEstimateCostTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_estimate_cost';

    protected string $description = 'Estimate the credit cost to run a workflow once. Returns an estimated total in credits based on the agent and skill nodes in the graph.';

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

        $workflow = Workflow::with(['nodes'])->find($validated['workflow_id']);

        if (! $workflow) {
            return $this->notFoundError('workflow');
        }

        try {
            $credits = app(EstimateWorkflowCostAction::class)->execute($workflow);

            return Response::text(json_encode([
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'estimated_cost_credits' => $credits,
                'estimated_cost_usd' => round($credits * 0.001, 4),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
