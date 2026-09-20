<?php

namespace App\Domain\Decision\Exceptions;

use RuntimeException;

/**
 * A decision call failed. The message carries the status and the API's own
 * error body — never the request headers, because those hold the bearer token.
 */
class DecisionRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }
}
