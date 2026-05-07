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

    protected string $description = <<<'TXT'
Platform super-admin actions: suspend tenants, adjust billing, force-rotate user sessions, browse cross-tenant feedback. Restricted to users with the platform `admin` role (HTTP 403 for everyone else). Every write is audit-logged.

Actions (pass alongside `action`):
- team_suspend (DESTRUCTIVE) — team_id, reason. Disables tenant logins.
- team_billing (read) — team_id. Returns current invoice + plan.
- billing_credit (write) — team_id, amount, reason. Credits the ledger.
- billing_refund (DESTRUCTIVE) — team_id, amount. Issues a Stripe refund.
- security_overview (read) — no params. Recent suspicious activity.
- user_revoke_sessions (DESTRUCTIVE) — user_id. Invalidates all sessions and tokens.
- user_send_password_reset (write) — user_id. Emails a reset link.
- feedback_list (read) — optional status filter.
- feedback_update (write) — feedback_id, status.
TXT;

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
