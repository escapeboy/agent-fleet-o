<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentStepsTool extends Tool
{
    protected string $name = 'experiment_steps';

    protected string $description = 'List all playbook steps for a workflow experiment in execution order. Returns step id, order, status, skill_id, cost_credits, duration_ms, and timestamps.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['experiment_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->orderBy('order')
            ->get();

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'count' => $steps->count(),
            'steps' => $steps->map(fn ($s) => [
                'id' => $s->id,
                'order' => $s->order,
                'status' => $s->status,
                'workflow_node_id' => $s->workflow_node_id,
                'agent_id' => $s->agent_id,
                'skill_id' => $s->skill_id,
                'crew_id' => $s->crew_id,
                'cost_credits' => $s->cost_credits,
                'duration_ms' => $s->duration_ms,
                'error_message' => $s->error_message,
                'started_at' => $s->started_at?->toIso8601String(),
                'completed_at' => $s->completed_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
