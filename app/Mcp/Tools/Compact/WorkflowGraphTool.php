<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Workflow\WorkflowEdgeAddTool;
use App\Mcp\Tools\Workflow\WorkflowEdgeDeleteTool;
use App\Mcp\Tools\Workflow\WorkflowNodeAddTool;
use App\Mcp\Tools\Workflow\WorkflowNodeDeleteTool;
use App\Mcp\Tools\Workflow\WorkflowNodeUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowSaveGraphTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowGraphTool extends CompactTool
{
    protected string $name = 'workflow_graph';

    protected string $description = <<<'TXT'
Surgical edits to a workflow's DAG (nodes + edges). For metadata changes (name, description, status, validation, AI generation) use `workflow_manage`. Every edit re-validates the graph; an edit that would create a cycle, an unreachable node, or an invalid edge type is rejected before commit.

Actions:
- save_graph (write — full replace) — workflow_id, nodes[], edges[]. Atomically replaces the entire graph; existing in-flight runs continue on the old graph.
- node_add (write) — workflow_id, type (start|end|agent|conditional|human_task|switch|dynamic_fork|do_while), config (type-specific).
- node_update (write) — workflow_id, node_id, config (partial).
- node_delete (DESTRUCTIVE) — workflow_id, node_id. Cascade-deletes incident edges; rejected if it would orphan nodes.
- edge_add (write) — workflow_id, source_id, target_id; optional condition / case_value for switch nodes.
- edge_delete (DESTRUCTIVE) — workflow_id, edge_id. Rejected if it would disconnect the graph.
TXT;

    protected function toolMap(): array
    {
        return [
            'save_graph' => WorkflowSaveGraphTool::class,
            'node_update' => WorkflowNodeUpdateTool::class,
            'node_add' => WorkflowNodeAddTool::class,
            'node_delete' => WorkflowNodeDeleteTool::class,
            'edge_add' => WorkflowEdgeAddTool::class,
            'edge_delete' => WorkflowEdgeDeleteTool::class,
        ];
    }
}
