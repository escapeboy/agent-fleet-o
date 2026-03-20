<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Signal\ConnectorBindingDeleteTool;
use App\Mcp\Tools\Signal\ConnectorBindingTool;
use App\Mcp\Tools\Signal\ContactManageTool;
use App\Mcp\Tools\Signal\EmailReplyTool;
use App\Mcp\Tools\Signal\ImapMailboxTool;
use App\Mcp\Tools\Signal\SignalGetTool;
use App\Mcp\Tools\Signal\SignalIngestTool;
use App\Mcp\Tools\Signal\SignalListTool;

class SignalManageTool extends CompactTool
{
    protected string $name = 'signal_manage';

    protected string $description = 'Manage inbound signals. Actions: list (status, source filter), get (signal_id), ingest (source, payload), connector_binding (connector_id, channel_id), connector_binding_delete (binding_id), contact (action, contact data), imap (mailbox config), email_reply (signal_id, body).';

    protected function toolMap(): array
    {
        return [
            'list' => SignalListTool::class,
            'get' => SignalGetTool::class,
            'ingest' => SignalIngestTool::class,
            'connector_binding' => ConnectorBindingTool::class,
            'connector_binding_delete' => ConnectorBindingDeleteTool::class,
            'contact' => ContactManageTool::class,
            'imap' => ImapMailboxTool::class,
            'email_reply' => EmailReplyTool::class,
        ];
    }
}
