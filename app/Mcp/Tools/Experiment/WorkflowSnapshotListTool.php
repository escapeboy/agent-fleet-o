<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\WorkflowSnapshot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[IsReadOnly]
class WorkflowSnapshotListTool extends Tool
{
    protected string $name = 'workflow_snapshot_list';

    protected string $description = 'List time-travel snapshots for an experiment. Returns chronological execution snapshots showing graph state at each step.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'event_type' => $schema->string()
                ->description('Filter by event type: step_started, step_completed, step_failed, condition_evaluated, loop_iteration, human_decision, agent_handoff'),
            'limit' => $schema->integer()
                ->description('Max snapshots to return (default 50)')
                ->default(50)
                ->minimum(1)
                ->maximum(200),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'event_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $query = WorkflowSnapshot::where('experiment_id', $validated['experiment_id'])
            ->orderBy('sequence');

        if (! empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        $snapshots = $query->limit($validated['limit'] ?? 50)->get();

        return Response::text(json_encode([
            'count' => $snapshots->count(),
            'snapshots' => $snapshots->map(fn (WorkflowSnapshot $s) => [
                'id' => $s->id,
                'sequence' => $s->sequence,
                'event_type' => $s->event_type,
                'workflow_node_id' => $s->workflow_node_id,
                'duration_from_start_ms' => $s->duration_from_start_ms,
                'graph_state' => $s->graph_state,
                'step_input' => $s->step_input,
                'step_output' => $s->step_output,
                'metadata' => $s->metadata,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
