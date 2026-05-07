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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ToolManageTool extends CompactTool
{
    protected string $name = 'tool_manage';

    protected string $description = <<<'TXT'
LLM tool management — registers MCP servers (stdio/HTTP), built-in tools (bash/filesystem/browser/SSH), and external compute endpoints that agents can call at inference time. Tool execution may have any side effect declared by the underlying tool; the platform cannot constrain bash/filesystem/SSH effects beyond `bash_policy`.

CRUD actions:
- list / get (read) — optional: type, status filter.
- create (write) — name, type (mcp_stdio | mcp_http | built_in), config (type-specific).
- update (write) — tool_id + any creatable field.
- delete (DESTRUCTIVE) — tool_id. Soft-deletes; agents lose access on next resolve.
- activate / deactivate (write) — tool_id. Flips active flag without deleting.

Discovery & integration:
- discover_mcp (read — calls remote URL) — url. Probes a remote MCP server's tools/list without registering.
- import_mcp (write) — url, tool_names[]. Registers selected tools from a discovered MCP server.
- probe_remote (read) — url. Lightweight reachability check.
- ssh_fingerprints (read) — list known TOFU-trusted SSH host fingerprints.
- bash_policy (write — admin) — agent_id, policy (object: allowed_commands, disallowed_commands, working_dir).
TXT;

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
