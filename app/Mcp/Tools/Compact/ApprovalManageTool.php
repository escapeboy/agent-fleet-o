<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Approval\ApprovalApproveTool;
use App\Mcp\Tools\Approval\ApprovalCompleteHumanTaskTool;
use App\Mcp\Tools\Approval\ApprovalListTool;
use App\Mcp\Tools\Approval\ApprovalRejectTool;
use App\Mcp\Tools\Approval\ApprovalWebhookTool;
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
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => ApprovalListTool::class,
            'approve' => ApprovalApproveTool::class,
            'reject' => ApprovalRejectTool::class,
            'complete_human_task' => ApprovalCompleteHumanTaskTool::class,
            'webhook_config' => ApprovalWebhookTool::class,
        ];
    }
}
