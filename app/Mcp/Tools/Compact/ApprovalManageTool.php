<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Approval\ApprovalApproveTool;
use App\Mcp\Tools\Approval\ApprovalCompleteHumanTaskTool;
use App\Mcp\Tools\Approval\ApprovalListTool;
use App\Mcp\Tools\Approval\ApprovalRejectTool;
use App\Mcp\Tools\Approval\ApprovalWebhookTool;
use App\Mcp\Tools\Inbox\InboxListTool;
use App\Mcp\Tools\Inbox\InboxQueueCreateTool;
use App\Mcp\Tools\Inbox\InboxQueueDeleteTool;
use App\Mcp\Tools\Inbox\InboxQueueListTool;
use App\Mcp\Tools\Inbox\InboxRefineTriageTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ApprovalManageTool extends CompactTool
{
    protected string $name = 'approval_manage';

    protected string $description = <<<'TXT'
Human-in-the-loop approvals and workflow human-task completion. Use this to unblock workflow steps gated on reviewer decisions or to submit form data for `human_task` DAG nodes. Each decision is audit-logged and emits a domain event the workflow runtime listens for.

Actions:
- list (read) — optional: status (pending/approved/rejected/expired), assignee_id, limit.
- approve (write) — approval_id, optional comment. Unblocks the dependent step.
- reject (write) — approval_id, reason. Terminates the dependent step (workflow may branch on rejection).
- complete_human_task (write) — approval_id, form_data (JSON matching the node's form_schema). Validates against the schema before commit.
- webhook_config (write) — approval_id, webhook_url. Configures external notification when status changes.
- inbox_list (read) — unified triage queue: pending approvals, human tasks and outbound proposals, scored and ranked. Filters: queue_id, kind, sort, limit.
- inbox_queue_list (read) — saved named filters over the inbox.
- inbox_queue_create (write) — name + kinds[]. Saves a filter.
- inbox_queue_delete (DESTRUCTIVE) — queue_id. Removes the saved view only; items are untouched.
- inbox_refine_triage (write — costs an LLM call) — item_id, kind. Re-scores one item with the triage LLM; falls back to the heuristic on budget cap or provider failure.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => ApprovalListTool::class,
            'approve' => ApprovalApproveTool::class,
            'reject' => ApprovalRejectTool::class,
            'complete_human_task' => ApprovalCompleteHumanTaskTool::class,
            'webhook_config' => ApprovalWebhookTool::class,
            'inbox_list' => InboxListTool::class,
            'inbox_queue_list' => InboxQueueListTool::class,
            'inbox_queue_create' => InboxQueueCreateTool::class,
            'inbox_queue_delete' => InboxQueueDeleteTool::class,
            'inbox_refine_triage' => InboxRefineTriageTool::class,
        ];
    }
}
