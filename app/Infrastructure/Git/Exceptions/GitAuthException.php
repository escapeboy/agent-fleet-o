<?php

namespace App\Infrastructure\Git\Exceptions;

use RuntimeException;

class GitAuthException extends RuntimeException
{
    public function __construct(string $provider = 'git')
    {
        parent::__construct("Authentication failed for {$provider} repository. Check that your credential token has the required permissions (repo scope).");
    }
}
