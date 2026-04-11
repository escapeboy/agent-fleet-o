<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Outbound\ConnectorConfigDeleteTool;
use App\Mcp\Tools\Outbound\ConnectorConfigGetTool;
use App\Mcp\Tools\Outbound\ConnectorConfigListTool;
use App\Mcp\Tools\Outbound\ConnectorConfigSaveTool;
use App\Mcp\Tools\Outbound\ConnectorConfigTestTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class OutboundManageTool extends CompactTool
{
    protected string $name = 'outbound_manage';

    protected string $description = 'Manage outbound delivery connectors (email, Slack, Telegram, webhook). Actions: list, get (connector_id), save (channel, config), delete (connector_id), test (connector_id, test_payload).';

    protected function toolMap(): array
    {
        return [
            'list' => ConnectorConfigListTool::class,
            'get' => ConnectorConfigGetTool::class,
            'save' => ConnectorConfigSaveTool::class,
            'delete' => ConnectorConfigDeleteTool::class,
            'test' => ConnectorConfigTestTool::class,
        ];
    }
}
