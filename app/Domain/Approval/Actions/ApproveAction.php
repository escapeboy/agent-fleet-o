<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Jobs\FireApprovalWebhookJob;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Chatbot\Events\ChatbotResponseApprovedEvent;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApproveAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(ApprovalRequest $approvalRequest, ?string $reviewerId, ?string $notes = null): void
    {
        DB::transaction(function () use ($approvalRequest, $reviewerId, $notes): void {
            // Re-fetch with row lock so concurrent approve/reject calls
            // serialize on the same row. Without the lock two parallel
            // requests both pass the pending check and fire webhook /
            // credential activation twice.
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
                'status' => ApprovalStatus::Approved,
                'reviewed_by' => $reviewerId,
                'reviewer_notes' => $notes,
                'reviewed_at' => now(),
            ]);

            // Reflect the freshly loaded state on the caller-supplied model
            // so downstream code that already holds the original instance
            // sees the same data.
            $approvalRequest->setRawAttributes($locked->getAttributes(), true);

            // Chatbot response approval: deliver approved content via event
            if ($locked->isChatbotResponse()) {
                $message = ChatbotMessage::find($locked->chatbot_message_id);
                if ($message) {
                    $approvedContent = $locked->edited_content ?? $message->draft_content ?? '';
                    $message->update([
                        'content' => $approvedContent,
                        'was_escalated' => true,
                    ]);

                    DB::afterCommit(function () use ($message, $approvedContent): void {
                        ChatbotResponseApprovedEvent::dispatch(
                            chatbotMessageId: $message->id,
                            sessionId: $message->session_id,
                            approvedContent: $approvedContent,
                        );
                    });
                }

                $this->scheduleCallback($locked);

                return;
            }

            // Credential review approval: activate the credential
            if ($locked->isCredentialReview()) {
                $credential = $locked->credential;
                if ($credential) {
                    $credential->update(['status' => CredentialStatus::Active]);
                }

                $ocsf = OcsfMapper::classify('approval.approved');
                AuditEntry::withoutGlobalScopes()->create([
                    'user_id' => $reviewerId,
                    'team_id' => $locked->team_id,
                    'event' => 'approval.approved',
                    'ocsf_class_uid' => $ocsf['class_uid'],
                    'ocsf_severity_id' => $ocsf['severity_id'],
                    'subject_type' => ApprovalRequest::class,
                    'subject_id' => $locked->id,
                    'properties' => [
                        'credential_id' => $locked->credential_id,
                        'notes' => $notes,
                    ],
                    'created_at' => now(),
                ]);

                $this->scheduleCallback($locked);

                return;
            }

            // Approve all proposals in the batch
            $experiment = $locked->experiment;
            $experiment->outboundProposals()
                ->where('status', OutboundProposalStatus::PendingApproval)
                ->update(['status' => OutboundProposalStatus::Approved]);

            $ocsf = OcsfMapper::classify('approval.approved');
            AuditEntry::withoutGlobalScopes()->create([
                'user_id' => $reviewerId,
                'team_id' => $experiment->team_id,
                'event' => 'approval.approved',
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'subject_type' => ApprovalRequest::class,
                'subject_id' => $locked->id,
                'properties' => [
                    'experiment_id' => $experiment->id,
                    'notes' => $notes,
                ],
                'created_at' => now(),
            ]);

            // Transition experiment to approved, which triggers executing.
            // TransitionExperimentAction nests its own SELECT FOR UPDATE; the
            // outer transaction here uses Postgres savepoints so nested locks
            // are safe.
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

            $this->scheduleCallback($locked);
        });
    }

    /**
     * Mark the request as having a pending callback (so polling stays in
     * sync) and queue the webhook dispatch for after commit so a rolled-back
     * approval never fires the callback.
     */
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
