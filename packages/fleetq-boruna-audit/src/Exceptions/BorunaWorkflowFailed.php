<?php

namespace FleetQ\BorunaAudit\Exceptions;

use RuntimeException;

class BorunaWorkflowFailed extends RuntimeException
{
    public function __construct(string $workflowName, string $reason)
    {
        parent::__construct("Boruna workflow '{$workflowName}' failed: {$reason}");
    }
}
