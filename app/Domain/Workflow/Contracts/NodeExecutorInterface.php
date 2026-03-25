<?php

namespace App\Domain\Workflow\Contracts;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\WorkflowNode;

interface NodeExecutorInterface
{
    /**
     * Execute a workflow node and return its output data.
     *
     * @return array<string, mixed>
     */
    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array;
}
