<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * List failed workflow-run experiments that had compensation-eligible steps.
 *
 * Compensation (saga) runs are NOT persisted as their own queryable records, so
 * this tool reconstructs them exactly like WorkflowOpsPage: failed workflow
 * experiments whose completed steps had a workflow node with a
 * compensation_node_id (i.e. steps that would have been rolled back). Read-only.
 */
#[IsReadOnly]
#[IsIdempotent]
class WorkflowCompensationRunsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_compensation_runs';

    protected string $description = 'List failed workflow-run experiments that had compensation-eligible steps (saga rollback candidates). Reconstructed from failed experiments whose completed steps had a workflow node with a compensation node defined.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        // Only failed workflow-run experiments can trigger compensation.
        $experiments = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('workflow_id')
            ->whereIn('status', ['execution_failed', 'building_failed', 'planning_failed', 'scoring_failed'])
            ->with('workflow:id,name')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $runs = $experiments
            ->map(function (Experiment $experiment): array {
                $completedNodeIds = PlaybookStep::query()
                    ->where('experiment_id', $experiment->id)
                    ->where('status', 'completed')
                    ->whereNotNull('workflow_node_id')
                    ->pluck('workflow_node_id');

                if ($completedNodeIds->isEmpty()) {
                    return ['experiment' => $experiment, 'compensated_count' => 0];
                }

                $compensatedCount = WorkflowNode::withoutGlobalScopes()
                    ->whereHas('workflow', fn ($q) => $q->where('team_id', $experiment->team_id))
                    ->whereIn('id', $completedNodeIds)
                    ->whereNotNull('compensation_node_id')
                    ->count();

                return ['experiment' => $experiment, 'compensated_count' => $compensatedCount];
            })
            ->filter(fn (array $row): bool => $row['compensated_count'] > 0)
            ->values()
            ->map(fn (array $row): array => [
                'experiment_id' => $row['experiment']->id,
                'workflow_id' => $row['experiment']->workflow_id,
                'workflow_name' => $row['experiment']->workflow?->name,
                'status' => $row['experiment']->status->value,
                'compensated_count' => $row['compensated_count'],
                'updated_at' => $row['experiment']->updated_at?->toIso8601String(),
            ]);

        return Response::text(json_encode([
            'count' => $runs->count(),
            'runs' => $runs->toArray(),
        ]));
    }
}
