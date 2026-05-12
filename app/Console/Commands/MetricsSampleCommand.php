<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\Observability\Prometheus\MetricEmitter;
use App\Infrastructure\Observability\Prometheus\TopNTeamLabeller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Periodic Prometheus gauge sampler.
 *
 * Counters (HTTP/queue events) are emitted by listeners; this command exists
 * to keep *gauge* metrics fresh because they aren't event-driven:
 *   - fleetq_queue_depth{queue}: current Redis LLEN per Horizon queue
 *   - fleetq_experiment_stuck_count: count of stages stuck >15 min
 *   - fleetq_circuit_breaker_open_count: count of AI provider breakers open
 *
 * Also calls TopNTeamLabeller::refreshTopN() so the cardinality rollup stays
 * fresh between metric emissions.
 *
 * Schedule: every 15 seconds via routes/console.php. Run-cost: ~5 Redis LLENs +
 * 2 Postgres COUNTs (~50ms total). Trivial.
 */
class MetricsSampleCommand extends Command
{
    protected $signature = 'metrics:sample {--trim : Trim the top-N ranking sorted set after sampling (run hourly)}';

    protected $description = 'Sample Prometheus gauges for queue depth, stuck experiments, circuit breakers.';

    /** @var array<int, string> */
    private array $monitoredQueues = ['critical', 'ai-calls', 'experiments', 'outbound', 'metrics', 'default'];

    public function handle(MetricEmitter $emitter, TopNTeamLabeller $labeller): int
    {
        if (! $emitter->isEnabled()) {
            $this->info('Prometheus disabled (observability.prometheus.enabled=false) — skipping sample.');

            return self::SUCCESS;
        }

        $this->sampleQueueDepths($emitter);
        $this->sampleStuckExperiments($emitter);
        $this->sampleCircuitBreakers($emitter);

        $labeller->refreshTopN();
        if ($this->option('trim')) {
            $labeller->trimRanking();
        }

        return self::SUCCESS;
    }

    private function sampleQueueDepths(MetricEmitter $emitter): void
    {
        foreach ($this->monitoredQueues as $queue) {
            try {
                // Horizon stores ready jobs at `queues:{name}` (Redis list) on the default Redis connection.
                $depth = (int) Redis::connection()->llen("queues:{$queue}");
                $emitter->setQueueDepth($queue, $depth);
            } catch (Throwable $e) {
                $this->warn("Failed to read queue depth for {$queue}: ".$e->getMessage());
            }
        }
    }

    private function sampleStuckExperiments(MetricEmitter $emitter): void
    {
        try {
            $count = ExperimentStage::withoutGlobalScopes()
                ->whereIn('status', ['running', 'pending'])
                ->where('updated_at', '<', now()->subMinutes(15))
                ->count();

            $emitter->setStuckExperimentCount($count);
        } catch (Throwable $e) {
            $this->warn('Failed to sample stuck experiments: '.$e->getMessage());
        }
    }

    private function sampleCircuitBreakers(MetricEmitter $emitter): void
    {
        try {
            $count = CircuitBreakerState::withoutGlobalScopes()
                ->where('state', 'open')
                ->count();

            $emitter->setCircuitBreakerOpenCount($count);
        } catch (Throwable $e) {
            $this->warn('Failed to sample circuit breakers: '.$e->getMessage());
        }
    }
}
