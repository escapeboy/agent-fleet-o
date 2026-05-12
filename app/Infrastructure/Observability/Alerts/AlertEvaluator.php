<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Alerts;

use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\Observability\Prometheus\MetricEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Evaluates every AlertRule against current metric values and dispatches
 * `PlatformAlertTriggered` events when thresholds are crossed.
 *
 * Resolution strategy per metric:
 *   - queue_depth: total Redis LLEN across monitored queues (cheap, real-time).
 *   - error_rate_per_minute: Prometheus rate(fleetq_errors_total[1m]) when
 *     PROMETHEUS_API_URL is set; otherwise skips this rule.
 *   - p95_llm_latency_ms: Prometheus histogram_quantile; skips if Prom not set.
 *   - stuck_experiments: Postgres COUNT.
 *   - circuit_breaker_open: Postgres COUNT.
 *
 * Dedup: after each fire, sets a Redis SETEX cooldown so the same rule won't
 * re-fire within `observability.alerts.cooldown_seconds`.
 */
final class AlertEvaluator
{
    /** @var array<int, string> */
    private const MONITORED_QUEUES = ['critical', 'ai-calls', 'experiments', 'outbound', 'metrics', 'default'];

    public function __construct(
        private readonly AlertRules $rules,
        private readonly ConfigRepository $config,
        private readonly Dispatcher $events,
        private readonly HttpFactory $http,
    ) {}

    /**
     * @return array<int, PlatformAlertTriggered> Fired (post-dedup) events.
     */
    public function evaluate(): array
    {
        $cooldown = (int) $this->config->get('observability.alerts.cooldown_seconds', 600);
        $fired = [];

        foreach ($this->rules->all() as $rule) {
            try {
                $current = $this->resolveMetric($rule->metricName);
            } catch (Throwable $e) {
                Log::warning('AlertEvaluator: metric resolution failed', [
                    'metric' => $rule->metricName,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($current === null) {
                // Resolver explicitly opted out (e.g. Prometheus unreachable for a Prom-only rule).
                continue;
            }

            if ($current < $rule->threshold) {
                // Below threshold — clear the dedup key so we can re-fire later.
                Cache::store('redis')->forget($rule->dedupeKey());

                continue;
            }

            // Dedup: skip if we already fired this rule within the cooldown.
            if (Cache::store('redis')->has($rule->dedupeKey())) {
                continue;
            }

            $event = new PlatformAlertTriggered(
                rule: $rule,
                currentValue: $current,
                triggeredAt: CarbonImmutable::now(),
            );

            Cache::store('redis')->put($rule->dedupeKey(), $current, $cooldown);
            $this->events->dispatch($event);

            $fired[] = $event;
        }

        return $fired;
    }

    /**
     * Resolve the current value of a metric. Returns null when not measurable
     * in the current environment (so the caller skips the rule cleanly).
     */
    private function resolveMetric(string $metric): int|float|null
    {
        return match ($metric) {
            'queue_depth' => $this->totalQueueDepth(),
            'stuck_experiments' => $this->stuckExperiments(),
            'circuit_breaker_open' => $this->circuitBreakersOpen(),
            'error_rate_per_minute' => $this->errorRatePerMinute(),
            'p95_llm_latency_ms' => $this->p95LlmLatencyMs(),
            default => null,
        };
    }

    private function totalQueueDepth(): int
    {
        $total = 0;
        foreach (self::MONITORED_QUEUES as $queue) {
            try {
                $total += (int) Redis::connection()->llen("queues:{$queue}");
            } catch (Throwable) {
                // Ignore individual queue failures.
            }
        }

        return $total;
    }

    private function stuckExperiments(): int
    {
        return ExperimentStage::withoutGlobalScopes()
            ->whereIn('status', ['running', 'pending'])
            ->where('updated_at', '<', now()->subMinutes(15))
            ->count();
    }

    private function circuitBreakersOpen(): int
    {
        $staleAfter = (int) $this->config->get('observability.alerts.breaker_stale_after_seconds', 3600);

        return CircuitBreakerState::withoutGlobalScopes()
            ->where('state', 'open')
            ->where('last_failure_at', '>=', now()->subSeconds($staleAfter))
            ->count();
    }

    private function errorRatePerMinute(): int|float|null
    {
        return $this->promQuery('sum(rate(fleetq_errors_total[1m])) * 60');
    }

    private function p95LlmLatencyMs(): int|float|null
    {
        return $this->promQuery('histogram_quantile(0.95, sum by (le) (rate(fleetq_llm_latency_ms_bucket[5m])))');
    }

    /**
     * Query Prometheus HTTP API. Returns null when the endpoint is unset
     * (graceful skip — Grafana alerting still works independently).
     */
    private function promQuery(string $promql): int|float|null
    {
        $url = trim((string) $this->config->get('observability.alerts.prometheus_api_url', ''));
        if ($url === '') {
            return null;
        }

        try {
            $response = $this->http->timeout(5)->get(rtrim($url, '/').'/api/v1/query', [
                'query' => $promql,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            $result = $body['data']['result'][0]['value'][1] ?? null;

            return $result === null ? null : (float) $result;
        } catch (Throwable) {
            return null;
        }
    }
}
