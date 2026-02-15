<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use Illuminate\Support\Facades\Log;

class EscalateHumanTaskAction
{
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
