<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Signal\BugReportAddCommentTool;
use App\Mcp\Tools\Signal\BugReportConfirmResolutionTool;
use App\Mcp\Tools\Signal\BugReportDeleteTool;
use App\Mcp\Tools\Signal\BugReportDetailTool;
use App\Mcp\Tools\Signal\BugReportListTool;
use App\Mcp\Tools\Signal\BugReportProjectConfigTool;
use App\Mcp\Tools\Signal\BugReportResolveStackTool;
use App\Mcp\Tools\Signal\BugReportUpdateStatusTool;
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
- bug_report_list (read) — Structured bug reports ingested from signals.
- bug_report_detail (read) — bug_report_id. Breadcrumbs, stack, comments.
- bug_report_add_comment (write) — bug_report_id, body.
- bug_report_update_status (write) — bug_report_id, status.
- bug_report_resolve_stack (write) — bug_report_id. Symbolicates the stack trace.
- bug_report_confirm_resolution (write) — bug_report_id. Marks a proposed fix as confirmed.
- bug_report_project_config (write) — Per-project bug-report intake configuration.
- bug_report_delete (DESTRUCTIVE) — bug_report_id. Removes the report permanently.
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
            'bug_report_list' => BugReportListTool::class,
            'bug_report_detail' => BugReportDetailTool::class,
            'bug_report_add_comment' => BugReportAddCommentTool::class,
            'bug_report_update_status' => BugReportUpdateStatusTool::class,
            'bug_report_resolve_stack' => BugReportResolveStackTool::class,
            'bug_report_confirm_resolution' => BugReportConfirmResolutionTool::class,
            'bug_report_project_config' => BugReportProjectConfigTool::class,
            'bug_report_delete' => BugReportDeleteTool::class,
        ];
    }
}
