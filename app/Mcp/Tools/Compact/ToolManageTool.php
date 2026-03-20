<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Tool\ToolActivateTool;
use App\Mcp\Tools\Tool\ToolBashPolicyTool;
use App\Mcp\Tools\Tool\ToolCreateTool;
use App\Mcp\Tools\Tool\ToolDeactivateTool;
use App\Mcp\Tools\Tool\ToolDeleteTool;
use App\Mcp\Tools\Tool\ToolDiscoverMcpTool;
use App\Mcp\Tools\Tool\ToolGetTool;
use App\Mcp\Tools\Tool\ToolImportMcpTool;
use App\Mcp\Tools\Tool\ToolListTool;
use App\Mcp\Tools\Tool\ToolProbeRemoteMcpTool;
use App\Mcp\Tools\Tool\ToolSshFingerprintsTool;
use App\Mcp\Tools\Tool\ToolUpdateTool;

class ToolManageTool extends CompactTool
{
    protected string $name = 'tool_manage';

    protected string $description = 'Manage LLM tools (MCP servers, built-in). Actions: list, get (tool_id), create (name, type, config), update (tool_id + fields), delete (tool_id), activate (tool_id), deactivate (tool_id), discover_mcp (url), import_mcp (url, tool_names), probe_remote (url), ssh_fingerprints, bash_policy (agent_id, policy).';

    protected function toolMap(): array
    {
        return [
            'list' => ToolListTool::class,
            'get' => ToolGetTool::class,
            'create' => ToolCreateTool::class,
            'update' => ToolUpdateTool::class,
            'delete' => ToolDeleteTool::class,
            'activate' => ToolActivateTool::class,
            'deactivate' => ToolDeactivateTool::class,
            'discover_mcp' => ToolDiscoverMcpTool::class,
            'import_mcp' => ToolImportMcpTool::class,
            'probe_remote' => ToolProbeRemoteMcpTool::class,
            'ssh_fingerprints' => ToolSshFingerprintsTool::class,
            'bash_policy' => ToolBashPolicyTool::class,
        ];
    }
}
