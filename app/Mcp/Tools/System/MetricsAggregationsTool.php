<?php

namespace App\Mcp\Tools\System;

use App\Domain\Metrics\Models\MetricAggregation;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MetricsAggregationsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'system_metrics_aggregations';

    protected string $description = 'Query aggregated metric summaries per period. Supports filtering by period (hourly/daily/weekly/monthly), metric_type, experiment_id, and date range.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->nullable()->description('Filter by aggregation period: hourly, daily, weekly, or monthly.'),
            'metric_type' => $schema->string()->nullable()->description('Filter by metric type key.'),
            'experiment_id' => $schema->string()->nullable()->description('Filter by experiment UUID.'),
            'from' => $schema->string()->nullable()->description('ISO date (inclusive) for period_start lower bound.'),
            'to' => $schema->string()->nullable()->description('ISO date (inclusive) for period_start upper bound.'),
            'limit' => $schema->integer()->nullable()->description('Maximum number of results to return (1–500, default 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null);

        if ($teamId === null) {
            return $this->permissionDeniedError('Authentication required.');
        }

        $period = $request->get('period');
        $metricType = $request->get('metric_type');
        $experimentId = $request->get('experiment_id');
        $from = $request->get('from');
        $to = $request->get('to');
        $limit = max(1, min((int) ($request->get('limit') ?? 100), 500));

        if ($from !== null && ! $this->isIsoDate($from)) {
            return $this->invalidArgumentError('Invalid date format for "from". Use ISO 8601 (YYYY-MM-DD).');
        }

        if ($to !== null && ! $this->isIsoDate($to)) {
            return $this->invalidArgumentError('Invalid date format for "to". Use ISO 8601 (YYYY-MM-DD).');
        }

        $aggregations = MetricAggregation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($period !== null, fn ($q) => $q->where('period', $period))
            ->when($metricType !== null, fn ($q) => $q->where('metric_type', $metricType))
            ->when($experimentId !== null, fn ($q) => $q->where('experiment_id', $experimentId))
            ->when($from !== null, fn ($q) => $q->where('period_start', '>=', $from))
            ->when($to !== null, fn ($q) => strlen($to) === 10
                // Date-only upper bound is inclusive of the whole day.
                ? $q->where('period_start', '<', Carbon::parse($to)->addDay()->toDateString())
                : $q->where('period_start', '<=', $to))
            ->orderByDesc('period_start')
            ->limit($limit)
            ->get(['id', 'experiment_id', 'metric_type', 'period', 'period_start', 'sum_value', 'count', 'avg_value', 'breakdown']);

        return Response::text(json_encode(['data' => $aggregations->toArray()]));
    }

    private function isIsoDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            && strtotime($value) !== false;
    }
}
