<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Models\DriftSignal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class DriftSignalListTool extends Tool
{
    protected string $name = 'drift_signal_list';

    protected string $description = 'List recent drift-signal observations (input shift, eval decay, thumbs-down, latency/cost) for the team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_type' => $schema->string()
                ->description('Filter: input_distribution_shift, eval_score_decay, thumbs_down_rate, latency_cost_spike'),
            'breached_only' => $schema->boolean()
                ->description('Only return breached signals'),
            'limit' => $schema->integer()
                ->description('Max rows (default 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No current team.']));
        }

        $signals = DriftSignal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($request->get('signal_type'), fn ($q, $t) => $q->where('signal_type', $t))
            ->when($request->get('breached_only'), fn ($q) => $q->where('breached', true))
            ->orderByDesc('detected_at')
            ->limit(min(200, max(1, (int) $request->get('limit', 50))))
            ->get(['id', 'signal_type', 'value', 'baseline', 'breached', 'window', 'detected_at', 'metadata']);

        return Response::text(json_encode(['drift_signals' => $signals->toArray()]));
    }
}
