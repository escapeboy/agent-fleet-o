<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Alerts;

use Carbon\CarbonImmutable;

/**
 * Fired by AlertEvaluator when a rule's threshold has been crossed.
 *
 * Listeners (e.g. SendAlertEmail) subscribe to format and dispatch the alert.
 */
final class PlatformAlertTriggered
{
    public function __construct(
        public readonly AlertRule $rule,
        public readonly int|float $currentValue,
        public readonly CarbonImmutable $triggeredAt,
    ) {}
}
