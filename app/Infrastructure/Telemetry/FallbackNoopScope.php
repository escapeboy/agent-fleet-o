<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

/**
 * No-op scope returned by FallbackNoopSpan::activate(). Absorbs detach()
 * and any other cleanup call.
 */
final class FallbackNoopScope
{
    public function __call(string $name, array $arguments): mixed
    {
        return 0; // detach() returns int (0 = noop) in OTel
    }
}
