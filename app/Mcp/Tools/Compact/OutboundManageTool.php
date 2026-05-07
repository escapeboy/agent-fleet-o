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

    protected string $description = <<<'TXT'
Outbound delivery connectors — channels through which agents send messages: email (SMTP), Slack, Telegram, generic webhook. One connector per channel per team (save uses upsert semantics). All sends pass through `ChannelRateLimit` and `TargetRateLimit` middleware and are recorded as `OutboundAction`s.

Actions:
- list / get (read).
- save (write — upsert) — channel (email/slack/telegram/webhook), config (channel-specific). Replaces any existing connector for the channel.
- delete (DESTRUCTIVE) — connector_id. Pending outbound actions on this connector are cancelled.
- test (write — sends a real test payload) — connector_id, test_payload (object). Counts against rate limits and budgets.
TXT;

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
