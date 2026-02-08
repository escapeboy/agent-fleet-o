<?php

namespace App\Console\Commands;

use App\Domain\Metrics\Models\Metric;
use App\Domain\Metrics\Models\MetricAggregation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateMetrics extends Command
{
    protected $signature = 'metrics:aggregate {--period=hourly : Aggregation period (hourly or daily)}';

    protected $description = 'Aggregate raw metrics into hourly or daily summaries';

    public function handle(): int
    {
        $period = $this->option('period');
        $periodStart = match ($period) {
            'daily' => now()->subDay()->startOfDay(),
            default => now()->subHour()->startOfHour(),
        };
        $periodEnd = match ($period) {
            'daily' => now()->startOfDay(),
            default => now()->startOfHour(),
        };

        $aggregates = Metric::select([
            'experiment_id',
            'type as metric_type',
            DB::raw('SUM(value) as sum_value'),
            DB::raw('COUNT(*) as count'),
            DB::raw('AVG(value) as avg_value'),
        ])
            ->whereBetween('occurred_at', [$periodStart, $periodEnd])
            ->groupBy('experiment_id', 'type')
            ->get();

        $created = 0;

        foreach ($aggregates as $agg) {
            MetricAggregation::updateOrCreate(
                [
                    'experiment_id' => $agg->experiment_id,
                    'metric_type' => $agg->metric_type,
                    'period' => $period,
                    'period_start' => $periodStart,
                ],
                [
                    'sum_value' => $agg->sum_value,
                    'count' => $agg->count,
                    'avg_value' => $agg->avg_value,
                    'breakdown' => [],
                ],
            );

            $created++;
        }

        $this->info("Aggregated {$created} metric group(s) for {$period} period starting {$periodStart}.");

        return self::SUCCESS;
    }
}
