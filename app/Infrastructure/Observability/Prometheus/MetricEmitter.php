<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Prometheus;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * High-level façade for emitting FleetQ business metrics to Prometheus.
 *
 * Wraps PrometheusRegistry with:
 *   - Auto-namespacing (everything prefixed `fleetq_`)
 *   - Top-N team rollup via TopNTeamLabeller (cardinality safety)
 *   - Try/catch fences so metric emission never breaks user paths
 *   - Configurable enable/disable flag (`observability.prometheus.enabled`)
 *
 * Metric naming follows Prometheus best practice:
 *   {namespace}_{unit-singular} for counters/gauges (e.g. fleetq_llm_requests_total)
 *   {namespace}_{unit-singular}_{ms,seconds,bytes} for histograms (e.g. fleetq_llm_latency_ms)
 *
 * Label whitelist (per metric): team_id, sub_program, provider, model, status,
 * byok_source, queue, channel, transition_from, transition_to.
 */
final class MetricEmitter
{
    private const NAMESPACE = 'fleetq';

    public function __construct(
        private readonly PrometheusRegistry $registry,
        private readonly TopNTeamLabeller $teamLabeller,
        private readonly ConfigRepository $config,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('observability.prometheus.enabled', true);
    }

    /**
     * Counter: a single LLM request completed.
     */
    public function llmRequestCompleted(
        string $provider,
        string $model,
        ?string $teamId,
        string $byokSource,
        string $status,
        int $latencyMs,
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $teamLabel = $this->teamLabeller->label($teamId);
        $this->teamLabeller->recordActivity($teamId);

        try {
            $registry = $this->registry->registry();

            $registry->getOrRegisterCounter(
                self::NAMESPACE,
                'llm_requests_total',
                'Count of LLM gateway calls grouped by provider, model, status.',
                ['provider', 'model', 'team_id', 'byok_source', 'status'],
            )->inc([$provider, $model, $teamLabel, $byokSource, $status]);

            $registry->getOrRegisterHistogram(
                self::NAMESPACE,
                'llm_latency_ms',
                'LLM call latency in milliseconds.',
                ['provider', 'model', 'team_id'],
                (array) $this->config->get('observability.prometheus.llm_latency_buckets_ms', [100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000, 120000]),
            )->observe($latencyMs, [$provider, $model, $teamLabel]);
        } catch (Throwable $e) {
            $this->logFailure('llmRequestCompleted', $e);
        }
    }

    /**
     * Counter: an LLM cost increment in credits.
     */
    public function llmCostCredits(string $provider, string $model, ?string $teamId, int $costCredits): void
    {
        if (! $this->isEnabled() || $costCredits <= 0) {
            return;
        }

        $teamLabel = $this->teamLabeller->label($teamId);

        try {
            $this->registry->registry()->getOrRegisterCounter(
                self::NAMESPACE,
                'llm_cost_credits_total',
                'Total LLM cost in FleetQ credits (1 credit = $0.001).',
                ['provider', 'model', 'team_id'],
            )->incBy($costCredits, [$provider, $model, $teamLabel]);
        } catch (Throwable $e) {
            $this->logFailure('llmCostCredits', $e);
        }
    }

    /**
     * Counter: experiment transitioned from one state to another.
     */
    public function experimentTransitioned(string $from, string $to, ?string $teamId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $teamLabel = $this->teamLabeller->label($teamId);
        $this->teamLabeller->recordActivity($teamId);

        try {
            $this->registry->registry()->getOrRegisterCounter(
                self::NAMESPACE,
                'experiment_transitions_total',
                'Count of experiment state transitions.',
                ['transition_from', 'transition_to', 'team_id'],
            )->inc([$from, $to, $teamLabel]);
        } catch (Throwable $e) {
            $this->logFailure('experimentTransitioned', $e);
        }
    }

    /**
     * Counter: an outbound channel send completed (success or failure).
     */
    public function outboundSent(string $channel, ?string $teamId, string $status): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $teamLabel = $this->teamLabeller->label($teamId);
        $this->teamLabeller->recordActivity($teamId);

        try {
            $this->registry->registry()->getOrRegisterCounter(
                self::NAMESPACE,
                'outbound_sent_total',
                'Count of outbound sends grouped by channel and status.',
                ['channel', 'team_id', 'status'],
            )->inc([$channel, $teamLabel, $status]);
        } catch (Throwable $e) {
            $this->logFailure('outboundSent', $e);
        }
    }

    /**
     * Counter: a captured error.
     */
    public function errorCaptured(string $subProgram, ?string $teamId, string $exceptionClass): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $teamLabel = $this->teamLabeller->label($teamId);

        try {
            $this->registry->registry()->getOrRegisterCounter(
                self::NAMESPACE,
                'errors_total',
                'Count of captured errors grouped by sub-program and exception class.',
                ['sub_program', 'team_id', 'exception_class'],
            )->inc([$subProgram, $teamLabel, $this->shortClass($exceptionClass)]);
        } catch (Throwable $e) {
            $this->logFailure('errorCaptured', $e);
        }
    }

    /**
     * Counter: a queue job failed (covers all jobs, not just our domain).
     */
    public function jobFailed(string $queue, string $jobClass): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $this->registry->registry()->getOrRegisterCounter(
                self::NAMESPACE,
                'horizon_job_failures_total',
                'Count of queue job failures grouped by queue and job class.',
                ['queue', 'job_class'],
            )->inc([$queue, $this->shortClass($jobClass)]);
        } catch (Throwable $e) {
            $this->logFailure('jobFailed', $e);
        }
    }

    /**
     * Gauge setters used by MetricsSampleCommand on its 15s heartbeat.
     */
    public function setQueueDepth(string $queue, int $depth): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $this->registry->registry()->getOrRegisterGauge(
                self::NAMESPACE,
                'queue_depth',
                'Approximate queue depth for the named Redis queue.',
                ['queue'],
            )->set($depth, [$queue]);
        } catch (Throwable $e) {
            $this->logFailure('setQueueDepth', $e);
        }
    }

    public function setStuckExperimentCount(int $count): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $this->registry->registry()->getOrRegisterGauge(
                self::NAMESPACE,
                'experiment_stuck_count',
                'Number of experiment stages stuck in pending/running beyond the configured threshold.',
                [],
            )->set($count, []);
        } catch (Throwable $e) {
            $this->logFailure('setStuckExperimentCount', $e);
        }
    }

    public function setCircuitBreakerOpenCount(int $count): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $this->registry->registry()->getOrRegisterGauge(
                self::NAMESPACE,
                'circuit_breaker_open_count',
                'Number of AI provider circuit breakers currently open.',
                [],
            )->set($count, []);
        } catch (Throwable $e) {
            $this->logFailure('setCircuitBreakerOpenCount', $e);
        }
    }

    public function setCreditBalance(?string $teamId, float $balance): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $teamLabel = $this->teamLabeller->label($teamId);

        try {
            $this->registry->registry()->getOrRegisterGauge(
                self::NAMESPACE,
                'credit_balance',
                'Current credit balance per top-team.',
                ['team_id'],
            )->set($balance, [$teamLabel]);
        } catch (Throwable $e) {
            $this->logFailure('setCreditBalance', $e);
        }
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function logFailure(string $method, Throwable $e): void
    {
        Log::warning('MetricEmitter: failed to emit metric', [
            'method' => $method,
            'error' => $e->getMessage(),
        ]);
    }
}
