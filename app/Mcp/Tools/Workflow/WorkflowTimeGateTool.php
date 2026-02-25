<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WorkflowTimeGateTool extends Tool
{
    protected string $name = 'workflow_time_gate_status';

    protected string $description = 'List active time gate steps (waiting_time) across all workflow experiments, or for a specific experiment. Useful for monitoring paused executions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('Filter to a specific experiment UUID (optional)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = PlaybookStep::where('status', 'waiting_time')
            ->whereNotNull('resume_at')
            ->orderBy('resume_at');

        $experimentId = $request->get('experiment_id');
        if ($experimentId) {
            $query->where('experiment_id', $experimentId);
        }

        $steps = $query->get()->map(fn ($step) => [
            'step_id' => $step->id,
            'experiment_id' => $step->experiment_id,
            'workflow_node_id' => $step->workflow_node_id,
            'resume_at' => $step->resume_at?->toIso8601String(),
            'seconds_remaining' => max(0, now()->diffInSeconds($step->resume_at, false)),
            'started_at' => $step->started_at?->toIso8601String(),
        ]);

        return Response::text(json_encode([
            'active_time_gates' => $steps->count(),
            'items' => $steps->toArray(),
        ]));
    }
}
