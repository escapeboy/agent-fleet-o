<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

class BridgeTimeoutException extends RuntimeException
{
    public function __construct(string $requestId, int $timeoutSeconds)
    {
        parent::__construct(
            "FleetQ Bridge relay timed out after {$timeoutSeconds}s waiting for request {$requestId}. "
            .'Ensure the bridge daemon is running and responsive.',
        );
    }
}
