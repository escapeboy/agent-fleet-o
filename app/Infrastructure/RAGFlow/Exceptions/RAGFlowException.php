<?php

namespace App\Infrastructure\RAGFlow\Exceptions;

use RuntimeException;

class RAGFlowException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
