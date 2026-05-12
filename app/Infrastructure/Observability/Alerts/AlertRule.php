<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Alerts;

/**
 * Immutable description of a single alert rule.
 *
 * `metricName` is one of the canonical FleetQ alert metric names — the
 * AlertEvaluator maps it to a real-time Prometheus query or a Postgres count
 * (depending on which is cheaper).
 *
 * `severity` follows the standard syslog levels (info / warning / critical).
 */
final class AlertRule
{
    public function __construct(
        public readonly string $metricName,
        public readonly int $threshold,
        public readonly string $severity,
        public readonly string $description,
    ) {}

    public function dedupeKey(): string
    {
        return "alert:fired:{$this->metricName}";
    }
}
