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

    protected string $description = 'Edit workflow DAG graph structure. Actions: save_graph (workflow_id, nodes, edges — full graph replace), node_update (workflow_id, node_id, config), node_add (workflow_id, type, config), node_delete (workflow_id, node_id), edge_add (workflow_id, source_id, target_id), edge_delete (workflow_id, edge_id).';

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
