<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Signal\ConnectorBindingDeleteTool;
use App\Mcp\Tools\Signal\ConnectorBindingTool;
use App\Mcp\Tools\Signal\ContactManageTool;
use App\Mcp\Tools\Signal\EmailReplyTool;
use App\Mcp\Tools\Signal\ImapMailboxTool;
use App\Mcp\Tools\Signal\SignalAssignTool;
use App\Mcp\Tools\Signal\SignalGetTool;
use App\Mcp\Tools\Signal\SignalIngestTool;
use App\Mcp\Tools\Signal\SignalListTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SignalManageTool extends CompactTool
{
    protected string $name = 'signal_manage';

    protected string $description = <<<'TXT'
Inbound signals — events from connectors (webhooks, RSS, email, Slack, ticketing) the platform processes through trigger rules into agent actions. Operates on already-ingested signals; for connector setup use `signal_connectors`.

Actions:
- list (read) — optional: status, source, channel, limit.
- get (read) — signal_id. Full payload + processing trail.
- ingest (write) — source, payload (object). Manually emits a signal as if from a connector; runs trigger evaluation.
- assign (write) — signal_id, assignee_user_id, reason.
- connector_binding (write) — connector_id, channel_id. Links a connector to a logical channel.
- connector_binding_delete (DESTRUCTIVE) — binding_id. Severs the link; future signals from that connector go unrouted.
- contact (write) — sub-actions on Contact (action, contact data).
- imap (write) — mailbox config object. Sets/updates IMAP poller settings.
- email_reply (write — sends email) — signal_id, body. Replies to the originating email signal via the team's outbound email connector.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => SignalListTool::class,
            'get' => SignalGetTool::class,
            'ingest' => SignalIngestTool::class,
            'assign' => SignalAssignTool::class,
            'connector_binding' => ConnectorBindingTool::class,
            'connector_binding_delete' => ConnectorBindingDeleteTool::class,
            'contact' => ContactManageTool::class,
            'imap' => ImapMailboxTool::class,
            'email_reply' => EmailReplyTool::class,
        ];
    }
}
