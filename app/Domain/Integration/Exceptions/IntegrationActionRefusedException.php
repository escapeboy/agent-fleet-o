<?php

namespace App\Domain\Integration\Exceptions;

use RuntimeException;

/**
 * Thrown by IntegrationActionGate when team policy is 'reject' for the
 * requested action's risk level. The caller must catch and surface a
 * "refused" response without creating a proposal record.
 */
class IntegrationActionRefusedException extends RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly string $riskLevel,
    ) {
        parent::__construct("Integration action '{$action}' refused by team policy ({$riskLevel}-risk → reject).");
    }
}
