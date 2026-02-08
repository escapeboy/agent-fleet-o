<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;

class LogApprovalDecision
{
    public function handle(object $event): void
    {
        if (!isset($event->approval) || !$event->approval instanceof ApprovalRequest) {
            return;
        }

        $approval = $event->approval;

        AuditEntry::create([
            'user_id' => $approval->reviewed_by,
            'event' => 'approval.' . $approval->status->value,
            'subject_type' => ApprovalRequest::class,
            'subject_id' => $approval->id,
            'properties' => [
                'experiment_id' => $approval->experiment_id,
                'status' => $approval->status->value,
                'rejection_reason' => $approval->rejection_reason,
            ],
            'created_at' => now(),
        ]);
    }
}
