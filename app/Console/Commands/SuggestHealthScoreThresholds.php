<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Agent\Models\AgentExecution;
use Illuminate\Console\Command;

/**
 * Read-only analyzer that recommends `config/agent-sla.php` threshold
 * adjustments based on real AgentExecution percentiles.
 *
 * The defaults that shipped with the per-agent SLA panel (P1) were
 * hand-tuned (5s/60s latency, 50/1000 credit cost). This command surfaces
 * the actual p50/p95/p99 distribution so an operator can decide whether
 * to retune via env vars. It does NOT auto-apply — it just prints
 * suggested SELF_SERVICE_* env values.
 *
 * Output formats:
 *   - markdown (default, human-readable)
 *   - json (machine-readable for downstream pipelines)
 *   - env (drop-in replacement values for .env)
 */
class SuggestHealthScoreThresholds extends Command
{
    protected $signature = 'health-score:suggest
        {--days=7 : Window of AgentExecution data to analyze (default: 7d)}
        {--format=markdown : markdown | json | env}
        {--team= : Limit to a single team UUID (default: all teams)}';

    protected $description = 'Analyze AgentExecution percentiles and propose tuned config(agent-sla.*) thresholds.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $format = (string) $this->option('format');
        $teamFilter = $this->option('team');

        if (! in_array($format, ['markdown', 'json', 'env'], true)) {
            $this->error("Unknown format '{$format}'. Use markdown, json, or env.");

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);

        $query = AgentExecution::query()
            ->withoutGlobalScopes()
            ->where('created_at', '>=', $cutoff)
            ->where('status', 'success'); // Only successful runs inform "healthy" thresholds

        if (is_string($teamFilter) && $teamFilter !== '') {
            $query->where('team_id', $teamFilter);
        }

        $latencies = $query->clone()
            ->whereNotNull('duration_ms')
            ->where('duration_ms', '>', 0)
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->all();

        $costs = $query->clone()
            ->whereNotNull('cost_credits')
            ->where('cost_credits', '>', 0)
            ->orderBy('cost_credits')
            ->pluck('cost_credits')
            ->all();

        $report = [
            'window_days' => $days,
            'team_filter' => $teamFilter,
            'sample_size_latency' => count($latencies),
            'sample_size_cost' => count($costs),
            'latency_ms' => $this->percentiles($latencies),
            'cost_credits' => $this->percentiles($costs),
            'current_config' => [
                'latency.healthy_ms' => (int) config('agent-sla.latency.healthy_ms', 5000),
                'latency.degraded_ms' => (int) config('agent-sla.latency.degraded_ms', 60000),
                'cost.healthy_credits' => (int) config('agent-sla.cost.healthy_credits', 50),
                'cost.degraded_credits' => (int) config('agent-sla.cost.degraded_credits', 1000),
            ],
            'suggestion' => $this->suggest($latencies, $costs),
        ];

        match ($format) {
            'json' => $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'env' => $this->renderEnv($report),
            default => $this->renderMarkdown($report),
        };

        return self::SUCCESS;
    }

    /**
     * @param  list<int|float>  $sortedValues
     * @return array{p50: int|null, p95: int|null, p99: int|null, max: int|null}
     */
    private function percentiles(array $sortedValues): array
    {
        $n = count($sortedValues);
        if ($n === 0) {
            return ['p50' => null, 'p95' => null, 'p99' => null, 'max' => null];
        }

        return [
            'p50' => (int) $sortedValues[(int) floor(0.50 * ($n - 1))],
            'p95' => (int) $sortedValues[(int) floor(0.95 * ($n - 1))],
            'p99' => (int) $sortedValues[(int) floor(0.99 * ($n - 1))],
            'max' => (int) end($sortedValues),
        ];
    }

    /**
     * @param  list<int|float>  $latencies
     * @param  list<int|float>  $costs
     * @return array{
     *   latency_healthy_ms: int|null,
     *   latency_degraded_ms: int|null,
     *   cost_healthy_credits: int|null,
     *   cost_degraded_credits: int|null,
     * }
     */
    private function suggest(array $latencies, array $costs): array
    {
        $latP = $this->percentiles($latencies);
        $costP = $this->percentiles($costs);

        return [
            // Healthy ≈ p50, degraded ≈ p95. Empirically tuned to give
            // a "healthy if better-than-median, degraded if worse-than-95%"
            // health score curve.
            'latency_healthy_ms' => $latP['p50'],
            'latency_degraded_ms' => $latP['p95'],
            'cost_healthy_credits' => $costP['p50'],
            'cost_degraded_credits' => $costP['p95'],
        ];
    }

    /** @param  array<string, mixed>  $report */
    private function renderMarkdown(array $report): void
    {
        $this->line('# Health Score Threshold Suggestion');
        $this->line(sprintf(
            'Window: last %d days | Team filter: %s',
            $report['window_days'],
            $report['team_filter'] ?: 'all teams',
        ));
        $this->newLine();

        $this->line('## Sample sizes');
        $this->line('- Latency observations: '.$report['sample_size_latency']);
        $this->line('- Cost observations: '.$report['sample_size_cost']);
        $this->newLine();

        if ($report['sample_size_latency'] === 0 && $report['sample_size_cost'] === 0) {
            $this->warn('No successful AgentExecution rows in the window — no suggestion possible.');

            return;
        }

        $this->line('## Latency (ms)');
        $this->table(
            ['p50', 'p95', 'p99', 'max'],
            [[
                $report['latency_ms']['p50'] ?? '—',
                $report['latency_ms']['p95'] ?? '—',
                $report['latency_ms']['p99'] ?? '—',
                $report['latency_ms']['max'] ?? '—',
            ]],
        );

        $this->line('## Cost (credits)');
        $this->table(
            ['p50', 'p95', 'p99', 'max'],
            [[
                $report['cost_credits']['p50'] ?? '—',
                $report['cost_credits']['p95'] ?? '—',
                $report['cost_credits']['p99'] ?? '—',
                $report['cost_credits']['max'] ?? '—',
            ]],
        );

        $this->newLine();
        $this->line('## Suggestion vs current');
        $this->table(
            ['Setting', 'Current', 'Suggested'],
            [
                ['latency.healthy_ms', $report['current_config']['latency.healthy_ms'], $report['suggestion']['latency_healthy_ms'] ?? '—'],
                ['latency.degraded_ms', $report['current_config']['latency.degraded_ms'], $report['suggestion']['latency_degraded_ms'] ?? '—'],
                ['cost.healthy_credits', $report['current_config']['cost.healthy_credits'], $report['suggestion']['cost_healthy_credits'] ?? '—'],
                ['cost.degraded_credits', $report['current_config']['cost.degraded_credits'], $report['suggestion']['cost_degraded_credits'] ?? '—'],
            ],
        );

        $this->newLine();
        $this->line('Run with `--format=env` to get drop-in `.env` values.');
    }

    /** @param  array<string, mixed>  $report */
    private function renderEnv(array $report): void
    {
        $s = $report['suggestion'];
        if ($s['latency_healthy_ms'] !== null) {
            $this->line('AGENT_SLA_LATENCY_HEALTHY_MS='.$s['latency_healthy_ms']);
        }
        if ($s['latency_degraded_ms'] !== null) {
            $this->line('AGENT_SLA_LATENCY_DEGRADED_MS='.$s['latency_degraded_ms']);
        }
        if ($s['cost_healthy_credits'] !== null) {
            $this->line('AGENT_SLA_COST_HEALTHY_CREDITS='.$s['cost_healthy_credits']);
        }
        if ($s['cost_degraded_credits'] !== null) {
            $this->line('AGENT_SLA_COST_DEGRADED_CREDITS='.$s['cost_degraded_credits']);
        }
    }
}
