<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * Minimal span builder used by FallbackNoopTracer. All chainable mutators
 * return $this, and startSpan() returns the always-loadable invalid span
 * from OpenTelemetry's API package, which itself is a no-op.
 */
final class FallbackNoopSpanBuilder implements SpanBuilderInterface
{
    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        return $this;
    }

    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        return $this;
    }

    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        return $this;
    }

    public function startSpan(): SpanInterface
    {
        return Span::getInvalid();
    }
}
