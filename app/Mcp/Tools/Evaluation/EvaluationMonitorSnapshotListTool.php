<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class EvaluationMonitorSnapshotListTool extends Tool
{
    protected string $name = 'evaluation_monitor_snapshots';

    protected string $description = 'List production eval-monitor score snapshots over time (the continuous-monitor series that feeds the eval-score-decay drift signal).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()
                ->description('Filter by dataset id'),
            'limit' => $schema->integer()
                ->description('Max rows (default 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No current team.']));
        }

        $snapshots = EvaluationMonitorSnapshot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($request->get('dataset_id'), fn ($q, $d) => $q->where('dataset_id', $d))
            ->orderByDesc('created_at')
            ->limit(min(200, max(1, (int) $request->get('limit', 50))))
            ->get(['id', 'dataset_id', 'avg_score', 'pass_rate', 'active_count', 'deferred_passed', 'sampled_count', 'created_at']);

        return Response::text(json_encode(['snapshots' => $snapshots->toArray()]));
    }
}
