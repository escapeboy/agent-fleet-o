<?php

namespace App\Infrastructure\Encryption\Kms\Exceptions;

use RuntimeException;

class KmsUnavailableException extends RuntimeException
{
    public function __construct(string $provider, string $reason = '', ?\Throwable $previous = null)
    {
        $message = "KMS provider '{$provider}' is unavailable";
        if ($reason) {
            $message .= ": {$reason}";
        }

        parent::__construct($message, 0, $previous);
    }
}
