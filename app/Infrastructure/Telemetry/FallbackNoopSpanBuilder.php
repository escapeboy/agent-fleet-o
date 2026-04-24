<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

/**
 * Minimal span builder used by FallbackNoopTracer. All methods are no-ops that
 * return $this (fluent interface) or a null-object span. Intentionally does
 * NOT implement SpanBuilderInterface via type — the fallback path is only
 * reached when OTel is absent, and callers of spanBuilder() use ->setAttribute
 * / ->startSpan() style chaining which we satisfy with __call.
 */
final class FallbackNoopSpanBuilder
{
    public function __call(string $name, array $arguments): mixed
    {
        // Any chained call (setAttribute, setAttributes, setParent, etc.) returns self.
        // startSpan() returns a no-op span object with a similar __call catch-all.
        if ($name === 'startSpan') {
            return new FallbackNoopSpan;
        }

        return $this;
    }
}
