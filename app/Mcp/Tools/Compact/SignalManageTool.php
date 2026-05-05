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

    protected string $description = 'Manage inbound signals. Actions: list (status, source filter), get (signal_id), ingest (source, payload), assign (signal_id, assignee_user_id, reason), connector_binding (connector_id, channel_id), connector_binding_delete (binding_id), contact (action, contact data), imap (mailbox config), email_reply (signal_id, body).';

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
