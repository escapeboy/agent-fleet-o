<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use InvalidArgumentException;

class ApproveAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(ApprovalRequest $approvalRequest, string $reviewerId, ?string $notes = null): void
    {
        if ($approvalRequest->status !== ApprovalStatus::Pending) {
            throw new InvalidArgumentException(
                "Approval request [{$approvalRequest->id}] is not pending."
            );
        }

        $approvalRequest->update([
            'status' => ApprovalStatus::Approved,
            'reviewed_by' => $reviewerId,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        // Approve all proposals in the batch
        $experiment = $approvalRequest->experiment;
        $experiment->outboundProposals()
            ->where('status', OutboundProposalStatus::PendingApproval)
            ->update(['status' => OutboundProposalStatus::Approved]);

        AuditEntry::withoutGlobalScopes()->create([
            'user_id' => $reviewerId,
            'team_id' => $experiment->team_id,
            'event' => 'approval.approved',
            'subject_type' => ApprovalRequest::class,
            'subject_id' => $approvalRequest->id,
            'properties' => [
                'experiment_id' => $experiment->id,
                'notes' => $notes,
            ],
            'created_at' => now(),
        ]);

        // Transition experiment to approved, which triggers executing
        $this->transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Approved,
            reason: 'Approved by operator',
            actorId: $reviewerId,
            metadata: ['notes' => $notes],
        );

        // Immediately transition to executing
        $this->transition->execute(
            experiment: Experiment::withoutGlobalScopes()->find($experiment->id),
            toState: ExperimentStatus::Executing,
            reason: 'Outbound dispatched after approval',
        );
    }
}
