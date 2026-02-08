<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use InvalidArgumentException;

class RejectAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(ApprovalRequest $approvalRequest, string $reviewerId, string $reason, ?string $notes = null): void
    {
        if ($approvalRequest->status !== ApprovalStatus::Pending) {
            throw new InvalidArgumentException(
                "Approval request [{$approvalRequest->id}] is not pending."
            );
        }

        $approvalRequest->update([
            'status' => ApprovalStatus::Rejected,
            'reviewed_by' => $reviewerId,
            'rejection_reason' => $reason,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        AuditEntry::create([
            'user_id' => $reviewerId,
            'event' => 'approval.rejected',
            'subject_type' => ApprovalRequest::class,
            'subject_id' => $approvalRequest->id,
            'properties' => [
                'experiment_id' => $approvalRequest->experiment_id,
                'reason' => $reason,
                'notes' => $notes,
            ],
            'created_at' => now(),
        ]);

        // Reject all proposals in the batch
        $experiment = $approvalRequest->experiment;
        $experiment->outboundProposals()
            ->where('status', OutboundProposalStatus::PendingApproval)
            ->update(['status' => OutboundProposalStatus::Rejected]);

        // Transition to rejected
        $this->transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Rejected,
            reason: $reason,
            actorId: $reviewerId,
            metadata: [
                'rejection_reason' => $reason,
                'notes' => $notes,
            ],
        );

        // Try to re-plan (will throw if max rejection cycles exceeded)
        try {
            $this->transition->execute(
                experiment: $experiment->fresh(),
                toState: ExperimentStatus::Planning,
                reason: 'Re-planning after rejection',
            );
        } catch (InvalidArgumentException $e) {
            // Max rejection cycles exceeded — kill the experiment
            $this->transition->execute(
                experiment: $experiment->fresh(),
                toState: ExperimentStatus::Killed,
                reason: 'Max rejection cycles exceeded',
            );
        }
    }
}
