<?php

namespace App\Infrastructure\Encryption\Kms\Exceptions;

use RuntimeException;

class KmsAccessDeniedException extends RuntimeException
{
    public function __construct(string $provider, string $reason = '', ?\Throwable $previous = null)
    {
        $message = "Access denied to KMS provider '{$provider}'";
        if ($reason) {
            $message .= ": {$reason}";
        }

        parent::__construct($message, 0, $previous);
    }
}
