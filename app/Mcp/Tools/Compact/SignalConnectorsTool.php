<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Signal\AlertConnectorTool;
use App\Mcp\Tools\Signal\ClearCueConnectorTool;
use App\Mcp\Tools\Signal\ConnectorSubscriptionTool;
use App\Mcp\Tools\Signal\HttpMonitorTool;
use App\Mcp\Tools\Signal\InboundConnectorManageTool;
use App\Mcp\Tools\Signal\IntentScoreTool;
use App\Mcp\Tools\Signal\KgAddFactTool;
use App\Mcp\Tools\Signal\KgEntityFactsTool;
use App\Mcp\Tools\Signal\KgSearchTool;
use App\Mcp\Tools\Signal\SlackConnectorTool;
use App\Mcp\Tools\Signal\SupabaseConnectorTool;
use App\Mcp\Tools\Signal\TicketConnectorTool;
use App\Mcp\Tools\Telegram\TelegramBotTool;

class SignalConnectorsTool extends CompactTool
{
    protected string $name = 'signal_connectors';

    protected string $description = 'Manage signal connectors and knowledge graph. Actions: ticket (config), alert (config), slack (config), http_monitor (url, interval), clearcue (config), supabase (config), intent_score (company, query), kg_search (query), kg_facts (entity_id), kg_add_fact (entity_id, fact), inbound_connector (create/update/delete connector), subscription (manage subscriptions), telegram (bot config).';

    protected function toolMap(): array
    {
        return [
            'ticket' => TicketConnectorTool::class,
            'alert' => AlertConnectorTool::class,
            'slack' => SlackConnectorTool::class,
            'http_monitor' => HttpMonitorTool::class,
            'clearcue' => ClearCueConnectorTool::class,
            'supabase' => SupabaseConnectorTool::class,
            'intent_score' => IntentScoreTool::class,
            'kg_search' => KgSearchTool::class,
            'kg_facts' => KgEntityFactsTool::class,
            'kg_add_fact' => KgAddFactTool::class,
            'inbound_connector' => InboundConnectorManageTool::class,
            'subscription' => ConnectorSubscriptionTool::class,
            'telegram' => TelegramBotTool::class,
        ];
    }
}
