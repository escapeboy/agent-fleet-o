<?php

namespace App\Domain\Workflow\Exceptions;

use RuntimeException;

class UnstubbedNodeException extends RuntimeException
{
    public function __construct(string $nodeId, string $nodeType, string $label)
    {
        parent::__construct(
            "Node '{$label}' (id={$nodeId}, type={$nodeType}) requires a stub output. "
            ."Call WorkflowSimulator::stub('{$nodeId}', \$output) before running.",
        );
    }
}
