<?php

namespace App\Domain\GitRepository\Exceptions;

use App\Domain\GitRepository\DTOs\TestRatchetVerdict;
use RuntimeException;

class TestRatchetViolationException extends RuntimeException
{
    public function __construct(public readonly TestRatchetVerdict $verdict)
    {
        parent::__construct(
            'TestRatchetViolation: '.$verdict->reason
            .' (deleted='.count($verdict->deletedTestFiles)
            .' modified='.count($verdict->modifiedTestFiles)
            .' removed_assertions='.$verdict->removedAssertionCount.')',
        );
    }
}
