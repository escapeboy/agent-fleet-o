<?php

declare(strict_types=1);

namespace App\Listeners\Observability;

use App\Infrastructure\Observability\Prometheus\MetricEmitter;
use Illuminate\Queue\Events\JobFailed;

/**
 * Subscribes to Laravel's queue JobFailed event and increments
 * `fleetq_horizon_job_failures_total{queue, job_class}`.
 *
 * Covers every queue job in the platform, not just FleetQ domain jobs.
 */
final class RecordPrometheusOnJobFailed
{
    public function __construct(
        private readonly MetricEmitter $emitter,
    ) {}

    public function handle(JobFailed $event): void
    {
        $payload = $event->job->payload();
        $jobClass = is_array($payload) ? ($payload['displayName'] ?? 'unknown') : 'unknown';

        $queue = method_exists($event->job, 'getQueue') ? (string) $event->job->getQueue() : 'default';

        $this->emitter->jobFailed($queue, $jobClass);
    }
}
