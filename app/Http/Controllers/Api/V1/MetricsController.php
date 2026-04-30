<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Metrics\Models\Metric;
use App\Domain\Metrics\Models\MetricAggregation;
use App\Http\Controllers\Controller;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Metrics
 */
class MetricsController extends Controller
{
    /**
     * List raw metrics with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'string', 'max:64'],
            'experiment_id' => ['sometimes', 'string'],
            'source' => ['sometimes', 'string', 'max:64'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $teamId = $request->user()->current_team_id;

        $metrics = QueryBuilder::for(Metric::class)
            ->allowedFilters(
                AllowedFilter::exact('type'),
                AllowedFilter::exact('experiment_id'),
                AllowedFilter::exact('source'),
            )
            ->allowedSorts('occurred_at', 'recorded_at', 'value')
            ->defaultSort('-occurred_at')
            ->where('team_id', $teamId)
            ->when($request->has('from'), fn ($q) => $q->where('occurred_at', '>=', $request->input('from')))
            ->when($request->has('to'), fn ($q) => $q->where('occurred_at', '<=', $request->input('to')))
            ->cursorPaginate(min((int) $request->input('per_page', 50), 200));

        return response()->json($metrics);
    }

    /**
     * Aggregated metric summaries per period.
     */
    public function aggregations(Request $request): JsonResponse
    {
        $request->validate([
            'period' => ['sometimes', 'in:hourly,daily,weekly,monthly'],
            'metric_type' => ['sometimes', 'string', 'max:64'],
            'experiment_id' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $teamId = $request->user()->current_team_id;

        $aggregations = MetricAggregation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($request->has('period'), fn ($q) => $q->where('period', $request->input('period')))
            ->when($request->has('metric_type'), fn ($q) => $q->where('metric_type', $request->input('metric_type')))
            ->when($request->has('experiment_id'), fn ($q) => $q->where('experiment_id', $request->input('experiment_id')))
            ->when($request->has('from'), fn ($q) => $q->where('period_start', '>=', $request->input('from')))
            ->when($request->has('to'), fn ($q) => $q->where('period_start', '<=', $request->input('to')))
            ->orderByDesc('period_start')
            ->limit((int) $request->input('limit', 100))
            ->get(['id', 'experiment_id', 'metric_type', 'period', 'period_start', 'sum_value', 'count', 'avg_value', 'breakdown']);

        return response()->json(['data' => $aggregations]);
    }

    /**
     * LLM model comparison: cost, latency, token usage by model.
     */
    public function modelComparison(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $teamId = $request->user()->current_team_id;

        $query = LlmRequestLog::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($request->has('from'), fn ($q) => $q->where('created_at', '>=', $request->input('from')))
            ->when($request->has('to'), fn ($q) => $q->where('created_at', '<=', $request->input('to')));

        $byModel = (clone $query)
            ->selectRaw('provider, model, count(*) as requests, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_credits) as cost_credits, avg(latency_ms) as avg_latency_ms')
            ->whereNotNull('model')
            ->groupBy('provider', 'model')
            ->orderByDesc('requests')
            ->get();

        $totals = (clone $query)
            ->selectRaw('count(*) as total_requests, sum(input_tokens) as total_input_tokens, sum(output_tokens) as total_output_tokens, sum(cost_credits) as total_cost_credits, avg(latency_ms) as avg_latency_ms')
            ->first();

        return response()->json([
            'totals' => $totals,
            'by_model' => $byModel,
        ]);
    }
}
