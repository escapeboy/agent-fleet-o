<?php

namespace App\Domain\Agent\Exceptions;

use RuntimeException;

class StrictProtocolViolationException extends RuntimeException
{
    public function __construct(string $message, public readonly array $violations = [])
    {
        parent::__construct($message);
    }
}
