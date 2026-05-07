<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Bridge\BridgeDisconnectTool;
use App\Mcp\Tools\Bridge\BridgeEndpointListTool;
use App\Mcp\Tools\Bridge\BridgeEndpointToggleTool;
use App\Mcp\Tools\Bridge\BridgeStatusTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class BridgeManageTool extends CompactTool
{
    protected string $name = 'bridge_manage';

    protected string $description = <<<'TXT'
FleetQ Bridge — a WebSocket relay that lets cloud experiments reach private endpoints on a self-hosted runner (laptop, on-prem VPS): local Ollama instances, internal MCP servers, on-prem APIs. All actions operate on the team's currently-registered bridge.

Actions:
- status (read) — connection state, last heartbeat, registered endpoint count.
- endpoint_list (read) — local LLM agents + MCP servers announced by the bridge.
- endpoint_toggle (write) — endpoint_id, enabled (bool). Flips visibility to cloud experiments without redeploying the bridge.
- disconnect (DESTRUCTIVE) — terminates the active bridge session; the runner must re-register before agents can reach private endpoints again.
TXT;

    protected function toolMap(): array
    {
        return [
            'status' => BridgeStatusTool::class,
            'endpoint_list' => BridgeEndpointListTool::class,
            'endpoint_toggle' => BridgeEndpointToggleTool::class,
            'disconnect' => BridgeDisconnectTool::class,
        ];
    }
}
