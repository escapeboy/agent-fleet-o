<?php

namespace App\Domain\Tool\Exceptions;

use RuntimeException;

class SshHostNotAllowedException extends RuntimeException
{
    public function __construct(string $host, string $reason = '')
    {
        $message = "SSH connection to '{$host}' is not allowed.";
        if ($reason) {
            $message .= " {$reason}";
        }

        parent::__construct($message);
    }
}
