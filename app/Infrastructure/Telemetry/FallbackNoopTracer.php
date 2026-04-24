<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Defensive fallback tracer used when the OpenTelemetry\API\Trace\NoopTracer
 * class is unavailable at runtime (e.g. when the deploy pipeline installs
 * the parent composer.json but the open-telemetry/* packages live only in
 * base/composer.json and weren't synced into the shared vendor volume).
 *
 * This class is NEVER used when OTel is installed — the TracerProvider
 * chooses the real NoopTracer via `class_exists()`. Kept as a belt-and-
 * suspenders guard so AI code paths don't crash on misconfigured deploys.
 *
 * TracerInterface itself IS imported — it's in open-telemetry/api which is
 * always present when either SDK or exporter is installed (transitive dep).
 */
final class FallbackNoopTracer implements TracerInterface
{
    public function spanBuilder(string $spanName): object
    {
        return new FallbackNoopSpanBuilder;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
