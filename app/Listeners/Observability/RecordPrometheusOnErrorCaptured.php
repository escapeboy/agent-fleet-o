<?php

declare(strict_types=1);

namespace App\Listeners\Observability;

use App\Infrastructure\Observability\Prometheus\MetricEmitter;
use App\Infrastructure\Telemetry\Sentry\ErrorCaptured;

/**
 * Subscribes to ErrorCaptured (fired by SentryEventCapturer) and increments
 * the `fleetq_errors_total{sub_program, team_id, exception_class}` counter.
 *
 * Wired in ObservabilityServiceProvider's `boot()`.
 */
final class RecordPrometheusOnErrorCaptured
{
    public function __construct(
        private readonly MetricEmitter $emitter,
    ) {}

    public function handle(ErrorCaptured $event): void
    {
        $this->emitter->errorCaptured(
            subProgram: $event->subProgram(),
            teamId: $event->teamId(),
            exceptionClass: $event->event->errorClass,
        );
    }
}
