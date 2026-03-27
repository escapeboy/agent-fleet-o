<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Integration\IntegrationCapabilitiesTool;
use App\Mcp\Tools\Integration\IntegrationConnectTool;
use App\Mcp\Tools\Integration\IntegrationDisconnectTool;
use App\Mcp\Tools\Integration\IntegrationExecuteTool;
use App\Mcp\Tools\Integration\IntegrationListTool;
use App\Mcp\Tools\Integration\IntegrationPingTool;

class IntegrationManageTool extends CompactTool
{
    protected string $name = 'integration_manage';

    protected string $description = 'Manage third-party integrations. Actions: list, connect (driver, name, credentials), disconnect (integration_id), ping (integration_id), execute (integration_id, integration_action, params), capabilities (integration_id).';

    protected function toolMap(): array
    {
        return [
            'list' => IntegrationListTool::class,
            'connect' => IntegrationConnectTool::class,
            'disconnect' => IntegrationDisconnectTool::class,
            'ping' => IntegrationPingTool::class,
            'execute' => IntegrationExecuteTool::class,
            'capabilities' => IntegrationCapabilitiesTool::class,
        ];
    }
}
