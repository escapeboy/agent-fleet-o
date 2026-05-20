<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry\Sentry;

use Throwable;

/**
 * Dispatched after SentryEventCapturer completes a capture.
 *
 * Subscribers (Prometheus listener, audit log, alert evaluators) can react
 * without coupling to the capturer. Sprint 3's
 * `RecordPrometheusOnErrorCaptured` listener increments
 * fleetq_errors_total{sub_program, team_id, exception_class}.
 */
final class ErrorCaptured
{
    public function __construct(
        public readonly CapturedEvent $event,
        public readonly Throwable $exception,
        /** @var array<string, mixed> */
        public readonly array $context,
    ) {}

    public function subProgram(): string
    {
        return (string) ($this->context['sub_program'] ?? 'unknown');
    }

    public function teamId(): ?string
    {
        $teamId = $this->context['team_id'] ?? null;

        return $teamId === null ? null : (string) $teamId;
    }
}
