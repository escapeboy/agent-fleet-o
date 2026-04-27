<?php

namespace App\Domain\GitRepository\Exceptions;

use RuntimeException;

/**
 * Thrown by GitOperationGate when team policy is 'reject' for the
 * requested method's risk level. The caller must catch and surface a
 * "refused" response without creating a proposal record.
 */
class GitOperationRefusedException extends RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly string $riskLevel,
    ) {
        parent::__construct("Git operation '{$method}' refused by team policy ({$riskLevel}-risk → reject).");
    }
}
