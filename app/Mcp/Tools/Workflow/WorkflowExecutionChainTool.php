<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\WorkflowNodeEvent;
use App\Domain\Workflow\Services\WorkflowEventRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowExecutionChainTool extends Tool
{
    protected string $name = 'workflow_execution_chain';

    protected string $description = 'Retrieve the event-chain execution trace for a workflow experiment. Shows the ordered sequence of node executions with durations and outcomes.';

    public function __construct(
        private readonly WorkflowEventRecorder $recorder,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID to fetch the execution chain for')
                ->required(),
            'event_type' => $schema->string()
                ->description('Filter by event type: started, completed, failed, waiting_time, waiting_human, skipped'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['experiment_id' => 'required|string']);
        $experimentId = $validated['experiment_id'];
        $eventType = $request->get('event_type');

        $query = WorkflowNodeEvent::where('experiment_id', $experimentId)
            ->orderBy('created_at');

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        $events = $query->get()->map(fn ($e) => [
            'id' => $e->id,
            'workflow_node_id' => $e->workflow_node_id,
            'node_type' => $e->node_type,
            'node_label' => $e->node_label,
            'event_type' => $e->event_type,
            'root_event_id' => $e->root_event_id,
            'parent_event_id' => $e->parent_event_id,
            'duration_ms' => $e->duration_ms,
            'output_summary' => $e->output_summary,
            'timestamp' => $e->created_at?->toIso8601String(),
        ]);

        $stats = $this->recorder->getStats($experimentId);

        return Response::text(json_encode([
            'experiment_id' => $experimentId,
            'stats' => $stats,
            'chain' => $events->toArray(),
        ]));
    }
}
