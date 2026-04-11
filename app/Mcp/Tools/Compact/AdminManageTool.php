<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Admin\AdminBillingApplyCreditTool;
use App\Mcp\Tools\Admin\AdminBillingRefundTool;
use App\Mcp\Tools\Admin\AdminSecurityOverviewTool;
use App\Mcp\Tools\Admin\AdminTeamBillingDetailTool;
use App\Mcp\Tools\Admin\AdminTeamSuspendTool;
use App\Mcp\Tools\Admin\AdminUserRevokeSessionsTool;
use App\Mcp\Tools\Admin\AdminUserSendPasswordResetTool;
use App\Mcp\Tools\Feedback\FeedbackListTool;
use App\Mcp\Tools\Feedback\FeedbackUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdminManageTool extends CompactTool
{
    protected string $name = 'admin_manage';

    protected string $description = 'Super admin operations (requires admin role). Actions: team_suspend (team_id, reason), team_billing (team_id — billing details), billing_credit (team_id, amount, reason), billing_refund (team_id, amount), security_overview, user_revoke_sessions (user_id), user_send_password_reset (user_id), feedback_list (list user feedback), feedback_update (feedback_id, status).';

    protected function toolMap(): array
    {
        return [
            'team_suspend' => AdminTeamSuspendTool::class,
            'team_billing' => AdminTeamBillingDetailTool::class,
            'billing_credit' => AdminBillingApplyCreditTool::class,
            'billing_refund' => AdminBillingRefundTool::class,
            'security_overview' => AdminSecurityOverviewTool::class,
            'user_revoke_sessions' => AdminUserRevokeSessionsTool::class,
            'user_send_password_reset' => AdminUserSendPasswordResetTool::class,
            'feedback_list' => FeedbackListTool::class,
            'feedback_update' => FeedbackUpdateTool::class,
        ];
    }
}
