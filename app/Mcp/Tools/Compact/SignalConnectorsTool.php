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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SignalConnectorsTool extends CompactTool
{
    protected string $name = 'signal_connectors';

    protected string $description = <<<'TXT'
Inbound signal connectors (ticketing, alerts, Slack, HTTP monitors, ClearCue, Supabase realtime, Telegram bots) plus the team's knowledge graph (KG) read/write surface. Distinct from `signal_manage`: this tool wires up SOURCES; signal_manage operates on already-ingested signals.

Connector actions (each accepts a `config` object specific to that channel):
- ticket / alert / slack / clearcue / supabase / telegram (write — upsert).
- http_monitor (write) — url, interval (seconds). Polls and emits a signal on change.
- inbound_connector (write) — sub-actions create/update/delete on generic connectors.
- subscription (write) — sub-actions list/create/delete on per-connector subscriptions.

Knowledge graph actions:
- kg_search (read) — query. Hybrid semantic + symbolic search across entities and facts.
- kg_facts (read) — entity_id. All facts attached to an entity.
- kg_add_fact (write) — entity_id, fact (object: predicate, object, source, confidence).
- intent_score (read — costs LLM credits) — company, query. Returns intent classification + score.
TXT;

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
