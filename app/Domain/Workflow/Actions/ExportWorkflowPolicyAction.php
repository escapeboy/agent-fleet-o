<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\PolicyExporter;

/**
 * Export a workflow's governance policy as a structured document.
 *
 * The policy document can be used for GitOps-style policy-as-code workflows,
 * audit trails, and external compliance tooling.
 */
class ExportWorkflowPolicyAction
{
    public function __construct(
        private readonly PolicyExporter $exporter,
    ) {}

    public function execute(Workflow $workflow): string
    {
        return $this->exporter->export($workflow);
    }
}
