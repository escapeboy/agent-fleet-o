<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Bridge\BridgeDisconnectTool;
use App\Mcp\Tools\Bridge\BridgeEndpointListTool;
use App\Mcp\Tools\Bridge\BridgeEndpointToggleTool;
use App\Mcp\Tools\Bridge\BridgeStatusTool;

class BridgeManageTool extends CompactTool
{
    protected string $name = 'bridge_manage';

    protected string $description = 'Manage local bridge agent relay. Actions: status (connection status), endpoint_list (list discovered endpoints), endpoint_toggle (endpoint_id, enabled), disconnect (terminate bridge connection).';

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
