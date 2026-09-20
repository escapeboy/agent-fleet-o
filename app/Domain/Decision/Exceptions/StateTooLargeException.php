<?php

namespace App\Domain\Decision\Exceptions;

use RuntimeException;

class StateTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $estimatedTokens,
        public readonly int $limit,
    ) {
        parent::__construct("Estimated {$estimatedTokens} tokens for state plus longest question exceeds the {$limit} token limit.");
    }
}
