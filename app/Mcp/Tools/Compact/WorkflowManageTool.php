<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Workflow\WorkflowActivateTool;
use App\Mcp\Tools\Workflow\WorkflowCreateTool;
use App\Mcp\Tools\Workflow\WorkflowDuplicateTool;
use App\Mcp\Tools\Workflow\WorkflowEstimateCostTool;
use App\Mcp\Tools\Workflow\WorkflowExecutionChainTool;
use App\Mcp\Tools\Workflow\WorkflowGenerateTool;
use App\Mcp\Tools\Workflow\WorkflowGetTool;
use App\Mcp\Tools\Workflow\WorkflowListTool;
use App\Mcp\Tools\Workflow\WorkflowSuggestionTool;
use App\Mcp\Tools\Workflow\WorkflowTimeGateTool;
use App\Mcp\Tools\Workflow\WorkflowUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowValidateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowManageTool extends CompactTool
{
    protected string $name = 'workflow_manage';

    protected string $description = <<<'TXT'
Workflow templates — reusable DAGs that experiments and project runs execute. This tool covers metadata and lifecycle; for graph editing use `workflow_graph`. Lifecycle states: draft → active → archived. Activation is gated on `validate` passing.

Core actions:
- list / get (read) — optional: status filter.
- create (write) — name, description.
- update (write) — workflow_id + any creatable field.
- delete (DESTRUCTIVE) — workflow_id. Soft-deletes; running experiments continue on cached graph.
- validate (read) — workflow_id. Returns errors[] (cycles, orphans, invalid types) and warnings[].
- activate (write) — workflow_id. Requires validation to pass.
- duplicate (write) — workflow_id. Creates a draft copy with the same graph.

AI / cost:
- generate (write — costs LLM credits) — prompt. Decomposes natural language into a workflow graph and saves as draft.
- estimate_cost (read) — workflow_id. Projected per-run credit cost.
- suggestion (read — costs LLM credits) — context (object). Recommends improvements.

Advanced:
- time_gate (write) — workflow_id, config (delay/window). Adds time-based gating around step execution.
- execution_chain (write) — workflow_id, chain config. Configures sequential workflow chaining.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => WorkflowListTool::class,
            'get' => WorkflowGetTool::class,
            'create' => WorkflowCreateTool::class,
            'update' => WorkflowUpdateTool::class,
            'validate' => WorkflowValidateTool::class,
            'activate' => WorkflowActivateTool::class,
            'duplicate' => WorkflowDuplicateTool::class,
            'generate' => WorkflowGenerateTool::class,
            'estimate_cost' => WorkflowEstimateCostTool::class,
            'suggestion' => WorkflowSuggestionTool::class,
            'time_gate' => WorkflowTimeGateTool::class,
            'execution_chain' => WorkflowExecutionChainTool::class,
        ];
    }
}
