<?php

namespace App\Domain\Crew\Exceptions;

use RuntimeException;

class CyclicDependencyException extends RuntimeException
{
    public function __construct(string $taskId, string $crewExecutionId)
    {
        parent::__construct(
            "Adding dependencies for task [{$taskId}] in crew execution [{$crewExecutionId}] "
            .'would create a cyclic dependency. Circular task graphs are not permitted.',
        );
    }
}
