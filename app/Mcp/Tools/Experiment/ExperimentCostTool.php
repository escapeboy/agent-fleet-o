<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentCostTool extends Tool
{
    protected string $name = 'experiment_cost';

    protected string $description = 'Get detailed cost breakdown for an experiment: total credits, token usage, cache hit count, cost by pipeline stage, and cost by model.';

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->string('experiment_id')->description('The UUID of the experiment')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = auth()->user()?->current_team_id;

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->findOrFail($request->get('experiment_id'));

        $runs = AiRun::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->with('experimentStage:id,stage_type')
            ->get();

        $totalCost = $runs->sum('cost_credits');
        $totalTokensIn = $runs->sum('input_tokens');
        $totalTokensOut = $runs->sum('output_tokens');
        $cachedCount = $runs->where('cost_credits', 0)->where('status', 'completed')->count();

        $nonCachedRuns = $runs->where('cost_credits', '>', 0);
        $avgCost = $nonCachedRuns->isNotEmpty() ? $nonCachedRuns->avg('cost_credits') : 0;
        $estimatedSavings = (int) round($cachedCount * $avgCost);

        $byStage = $runs
            ->filter(fn ($r) => $r->cost_credits > 0)
            ->groupBy(fn ($r) => $r->experimentStage?->stage_type ?? 'unknown')
            ->map(fn ($group) => [
                'runs' => $group->count(),
                'cost_credits' => $group->sum('cost_credits'),
                'tokens_in' => $group->sum('input_tokens'),
                'tokens_out' => $group->sum('output_tokens'),
            ])
            ->sortByDesc('cost_credits')
            ->values()
            ->toArray();

        $byModel = $runs
            ->filter(fn ($r) => $r->cost_credits > 0)
            ->groupBy(fn ($r) => "{$r->provider}/{$r->model}")
            ->map(fn ($group, $key) => [
                'provider_model' => $key,
                'runs' => $group->count(),
                'cost_credits' => $group->sum('cost_credits'),
                'tokens_in' => $group->sum('input_tokens'),
                'tokens_out' => $group->sum('output_tokens'),
                'avg_latency_ms' => (int) $group->avg('latency_ms'),
            ])
            ->sortByDesc('cost_credits')
            ->values()
            ->toArray();

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'experiment_title' => $experiment->title,
            'total_cost_credits' => $totalCost,
            'total_tokens_in' => $totalTokensIn,
            'total_tokens_out' => $totalTokensOut,
            'total_runs' => $runs->count(),
            'cached_runs' => $cachedCount,
            'estimated_savings_credits' => $estimatedSavings,
            'by_stage' => $byStage,
            'by_model' => $byModel,
        ]));
    }
}
