<?php

namespace App\Domain\Crew\Exceptions;

use RuntimeException;

class MaxDelegationDepthExceededException extends RuntimeException
{
    public function __construct(int $currentDepth, int $maxDepth)
    {
        parent::__construct(
            "Delegation depth {$currentDepth} exceeds the maximum allowed depth of {$maxDepth}. "
            .'This prevents runaway recursive agent delegation chains.'
        );
    }
}
