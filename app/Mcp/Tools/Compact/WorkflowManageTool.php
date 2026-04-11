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

    protected string $description = 'Manage workflow templates. Actions: list, get (workflow_id), create (name, description), update (workflow_id + fields), validate (workflow_id), activate (workflow_id), duplicate (workflow_id), generate (prompt — AI generates workflow from description), estimate_cost (workflow_id), suggestion (context), time_gate (workflow_id, config), execution_chain (workflow_id, chain config).';

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
