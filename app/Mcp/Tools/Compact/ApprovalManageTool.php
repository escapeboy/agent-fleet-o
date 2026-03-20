<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Approval\ApprovalApproveTool;
use App\Mcp\Tools\Approval\ApprovalCompleteHumanTaskTool;
use App\Mcp\Tools\Approval\ApprovalListTool;
use App\Mcp\Tools\Approval\ApprovalRejectTool;
use App\Mcp\Tools\Approval\ApprovalWebhookTool;

class ApprovalManageTool extends CompactTool
{
    protected string $name = 'approval_manage';

    protected string $description = 'Manage approvals and human tasks. Actions: list (status filter), approve (approval_id, comment), reject (approval_id, reason), complete_human_task (approval_id, form_data), webhook_config (approval_id, webhook_url).';

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
