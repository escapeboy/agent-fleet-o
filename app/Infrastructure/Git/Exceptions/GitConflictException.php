<?php

namespace App\Infrastructure\Git\Exceptions;

use RuntimeException;

class GitConflictException extends RuntimeException
{
    public function __construct(string $detail = '')
    {
        parent::__construct('Git conflict: ' . ($detail ?: 'The branch cannot be updated due to a conflict. Create a new branch or resolve conflicts first.'));
    }
}
