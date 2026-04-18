<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Jobs\FireApprovalWebhookJob;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RejectAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(ApprovalRequest $approvalRequest, string $reviewerId, string $reason, ?string $notes = null): void
    {
        DB::transaction(function () use ($approvalRequest, $reviewerId, $reason, $notes): void {
            // Lock the row so concurrent reject/approve calls cannot both
            // pass the pending check and double-fire credential disable +
            // webhook callbacks.
            $locked = ApprovalRequest::query()
                ->whereKey($approvalRequest->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new InvalidArgumentException(
                    "Approval request [{$approvalRequest->id}] not found.",
                );
            }

            if ($locked->status !== ApprovalStatus::Pending) {
                throw new InvalidArgumentException(
                    "Approval request [{$locked->id}] is not pending.",
                );
            }

            $locked->update([
                'status' => ApprovalStatus::Rejected,
                'reviewed_by' => $reviewerId,
                'rejection_reason' => $reason,
                'reviewer_notes' => $notes,
                'reviewed_at' => now(),
            ]);

            $approvalRequest->setRawAttributes($locked->getAttributes(), true);

            // Credential review rejection: disable the credential
            if ($locked->isCredentialReview()) {
                $credential = $locked->credential;
                if ($credential) {
                    $credential->update(['status' => CredentialStatus::Disabled]);
                }

                $ocsf = OcsfMapper::classify('approval.rejected');
                AuditEntry::withoutGlobalScopes()->create([
                    'user_id' => $reviewerId,
                    'team_id' => $locked->team_id,
                    'event' => 'approval.rejected',
                    'ocsf_class_uid' => $ocsf['class_uid'],
                    'ocsf_severity_id' => $ocsf['severity_id'],
                    'subject_type' => ApprovalRequest::class,
                    'subject_id' => $locked->id,
                    'properties' => [
                        'credential_id' => $locked->credential_id,
                        'reason' => $reason,
                        'notes' => $notes,
                    ],
                    'created_at' => now(),
                ]);

                $this->scheduleCallback($locked);

                return;
            }

            $ocsf = OcsfMapper::classify('approval.rejected');
            AuditEntry::create([
                'user_id' => $reviewerId,
                'event' => 'approval.rejected',
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'subject_type' => ApprovalRequest::class,
                'subject_id' => $locked->id,
                'properties' => [
                    'experiment_id' => $locked->experiment_id,
                    'reason' => $reason,
                    'notes' => $notes,
                ],
                'created_at' => now(),
            ]);

            // Reject all proposals in the batch
            $experiment = $locked->experiment;
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

            $this->scheduleCallback($locked);
        });
    }

    private function scheduleCallback(ApprovalRequest $approvalRequest): void
    {
        if (! $approvalRequest->callback_url) {
            return;
        }

        $approvalRequest->update(['callback_status' => 'pending']);

        DB::afterCommit(function () use ($approvalRequest): void {
            FireApprovalWebhookJob::dispatch($approvalRequest->id);
        });
    }
}
