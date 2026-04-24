<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

/**
 * No-op span returned by FallbackNoopSpanBuilder::startSpan(). All chainable
 * method calls (setAttribute, setStatus, recordException, end, activate,
 * detach, etc.) are silently absorbed. Pairs with FallbackNoopTracer.
 */
final class FallbackNoopSpan
{
    public function __call(string $name, array $arguments): mixed
    {
        // activate() conventionally returns a scope that can be detach()'d.
        if ($name === 'activate') {
            return new FallbackNoopScope;
        }

        return $this;
    }
}
