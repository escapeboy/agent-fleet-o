<?php

namespace App\Domain\Tool\Exceptions;

use RuntimeException;

class SshFingerprintMismatchException extends RuntimeException
{
    public function __construct(string $host, int $port)
    {
        parent::__construct(
            "SSH fingerprint for {$host}:{$port} does not match the stored fingerprint. "
            .'The host key may have changed. Verify the server identity before reconnecting.',
        );
    }
}
