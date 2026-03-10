<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class EscalateHumanTaskAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function execute(ApprovalRequest $approvalRequest): bool
    {
        if ($approvalRequest->status !== ApprovalStatus::Pending) {
            return false;
        }

        if (! $approvalRequest->hasEscalationLevels()) {
            return false;
        }

        $nextLevel = ($approvalRequest->escalation_level ?? 0) + 1;
        $chain = $approvalRequest->escalation_chain;
        $nextAssignee = $chain[$nextLevel - 1] ?? null;

        $approvalRequest->update([
            'escalation_level' => $nextLevel,
            'assigned_to' => $nextAssignee,
            'sla_deadline' => now()->addHours(
                $this->escalationSlaHours($approvalRequest, $nextLevel),
            ),
        ]);

        AuditEntry::withoutGlobalScopes()->create([
            'team_id' => $approvalRequest->team_id,
            'event' => 'human_task.escalated',
            'subject_type' => ApprovalRequest::class,
            'subject_id' => $approvalRequest->id,
            'properties' => [
                'experiment_id' => $approvalRequest->experiment_id,
                'escalation_level' => $nextLevel,
                'assigned_to' => $nextAssignee,
            ],
            'created_at' => now(),
        ]);

        Log::info('EscalateHumanTaskAction: Task escalated', [
            'approval_id' => $approvalRequest->id,
            'level' => $nextLevel,
            'assigned_to' => $nextAssignee,
        ]);

        // Notify the newly assigned user (if we have a user ID to target)
        if ($nextAssignee && $approvalRequest->team_id) {
            $this->notifications->notify(
                userId: $nextAssignee,
                teamId: $approvalRequest->team_id,
                type: 'approval.escalated',
                title: 'Human Task Escalated',
                body: 'A task has been escalated to you and requires your attention.',
                actionUrl: '/approvals',
                data: ['approval_id' => $approvalRequest->id, 'url' => '/approvals'],
            );
        }

        return true;
    }

    private function escalationSlaHours(ApprovalRequest $approvalRequest, int $level): int
    {
        // Each escalation level gets progressively shorter SLA
        $baseSla = 48;
        $context = $approvalRequest->context ?? [];

        if (isset($context['sla_hours'])) {
            $baseSla = (int) $context['sla_hours'];
        }

        return max(1, intdiv($baseSla, $level + 1));
    }
}
