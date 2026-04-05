<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;

class ExecuteSubWorkflowAction
{
    public function __construct(
        private readonly WorkflowGraphExecutor $executor,
    ) {}

    /**
     * Execute a workflow inline as a sub-flow, returning its accumulated step outputs.
     *
     * @param  array<string, mixed>  $input  Key-value input passed to the sub-workflow.
     * @param  int  $depth  Current nesting depth (caller increments before passing).
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the nesting depth exceeds the maximum allowed (3).
     */
    public function execute(Workflow $workflow, array $input, int $depth = 0): array
    {
        if ($depth >= 3) {
            throw new \RuntimeException('Workflow ref depth limit exceeded (max 3)');
        }

        // Run the referenced workflow's graph within the current request context.
        // The executor works on Experiment models, so for workflow_ref nodes we use
        // a lightweight context-only execution that resolves the graph from the
        // workflow's node/edge structure without creating a full Experiment row.
        // We pass depth+1 through the executor's context so nested workflow_ref
        // nodes can enforce the depth limit.
        return $this->executor->executeInline($workflow, $input, $depth + 1);
    }
}
