<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Tool\ToolActivateTool;
use App\Mcp\Tools\Tool\ToolBashPolicyTool;
use App\Mcp\Tools\Tool\ToolCreateTool;
use App\Mcp\Tools\Tool\ToolDeactivateTool;
use App\Mcp\Tools\Tool\ToolDeleteTool;
use App\Mcp\Tools\Tool\ToolDiscoverMcpTool;
use App\Mcp\Tools\Tool\ToolFederationEnableTool;
use App\Mcp\Tools\Tool\ToolFederationGroupCreateTool;
use App\Mcp\Tools\Tool\ToolFederationGroupListTool;
use App\Mcp\Tools\Tool\ToolFederationStatusTool;
use App\Mcp\Tools\Tool\ToolGetTool;
use App\Mcp\Tools\Tool\ToolImportMcpTool;
use App\Mcp\Tools\Tool\ToolListTool;
use App\Mcp\Tools\Tool\ToolProbeRemoteMcpTool;
use App\Mcp\Tools\Tool\ToolsetCreateTool;
use App\Mcp\Tools\Tool\ToolsetDeleteTool;
use App\Mcp\Tools\Tool\ToolsetGetTool;
use App\Mcp\Tools\Tool\ToolsetListTool;
use App\Mcp\Tools\Tool\ToolsetUpdateTool;
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
- toolset_list (read) — Named bundles of tools that can be attached to an agent.
- toolset_get (read) — toolset_id. Members and metadata.
- toolset_create (write) — name + tool ids.
- toolset_update (write) — toolset_id + any creatable field.
- toolset_delete (DESTRUCTIVE) — toolset_id. Agents referencing it lose the bundle.
- federation_status (read) — Whether cross-team tool federation is on and what it exposes.
- federation_enable (DESTRUCTIVE) — Turns federation on for the team.
- federation_group_list (read) — Federation groups this team participates in.
- federation_group_create (write) — name + members. Creates a federation group.
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
            'toolset_list' => ToolsetListTool::class,
            'toolset_get' => ToolsetGetTool::class,
            'toolset_create' => ToolsetCreateTool::class,
            'toolset_update' => ToolsetUpdateTool::class,
            'toolset_delete' => ToolsetDeleteTool::class,
            'federation_status' => ToolFederationStatusTool::class,
            'federation_enable' => ToolFederationEnableTool::class,
            'federation_group_list' => ToolFederationGroupListTool::class,
            'federation_group_create' => ToolFederationGroupCreateTool::class,
        ];
    }
}
