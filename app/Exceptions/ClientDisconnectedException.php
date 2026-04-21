<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by SSE stream callbacks when connection_aborted() reports the
 * client has closed the connection. Catching this exception should abort
 * any downstream LLM stream consumption so tokens are not wasted.
 */
class ClientDisconnectedException extends RuntimeException
{
    public function __construct(string $message = 'Client disconnected')
    {
        parent::__construct($message);
    }
}
