<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

class BridgeExecutionException extends RuntimeException
{
    public function __construct(string $message, string $requestId)
    {
        parent::__construct("FleetQ Bridge execution error for request {$requestId}: {$message}");
    }
}
