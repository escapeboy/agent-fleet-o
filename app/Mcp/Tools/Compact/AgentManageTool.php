<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Agent\AgentCreateTool;
use App\Mcp\Tools\Agent\AgentDeleteTool;
use App\Mcp\Tools\Agent\AgentGetTool;
use App\Mcp\Tools\Agent\AgentListTool;
use App\Mcp\Tools\Agent\AgentTemplatesListTool;
use App\Mcp\Tools\Agent\AgentToggleStatusTool;
use App\Mcp\Tools\Agent\AgentUpdateTool;

class AgentManageTool extends CompactTool
{
    protected string $name = 'agent_manage';

    protected string $description = 'Manage AI agents. Actions: list (filter by status, limit), get (agent_id), create (name, role, goal, provider, model), update (agent_id + fields), delete (agent_id, confirm=true), toggle_status (agent_id), templates (list agent templates).';

    protected function toolMap(): array
    {
        return [
            'list' => AgentListTool::class,
            'get' => AgentGetTool::class,
            'create' => AgentCreateTool::class,
            'update' => AgentUpdateTool::class,
            'delete' => AgentDeleteTool::class,
            'toggle_status' => AgentToggleStatusTool::class,
            'templates' => AgentTemplatesListTool::class,
        ];
    }
}
