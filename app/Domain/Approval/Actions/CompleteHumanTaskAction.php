<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CompleteHumanTaskAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(
        ApprovalRequest $approvalRequest,
        array $formResponse,
        string $reviewerId,
        ?string $notes = null,
    ): void {
        if ($approvalRequest->status !== ApprovalStatus::Pending) {
            throw new InvalidArgumentException(
                "Human task [{$approvalRequest->id}] is not pending.",
            );
        }

        if (! $approvalRequest->isHumanTask()) {
            throw new InvalidArgumentException(
                "Approval request [{$approvalRequest->id}] is not a human task.",
            );
        }

        // Handle clarification interrupt — re-dispatch the agent step with the answer
        if ($approvalRequest->isClarification()) {
            $this->completeClarificationRequest($approvalRequest, $formResponse, $reviewerId, $notes);

            return;
        }

        $approvalRequest->update([
            'status' => ApprovalStatus::Approved,
            'form_response' => $formResponse,
            'reviewed_by' => $reviewerId,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        // Find and complete the associated playbook step
        $step = PlaybookStep::withoutGlobalScopes()
            ->where('experiment_id', $approvalRequest->experiment_id)
            ->where('workflow_node_id', $approvalRequest->workflow_node_id)
            ->where('status', 'waiting_human')
            ->first();

        if ($step) {
            $step->update([
                'status' => 'completed',
                'output' => [
                    'form_response' => $formResponse,
                    'reviewer_notes' => $notes,
                    'completed_by' => $reviewerId,
                ],
                'completed_at' => now(),
            ]);

            // Continue workflow execution from this step
            $this->continueWorkflow($step);
        }

        $ocsf = OcsfMapper::classify('human_task.completed');
        AuditEntry::withoutGlobalScopes()->create([
            'user_id' => $reviewerId,
            'team_id' => $approvalRequest->team_id,
            'event' => 'human_task.completed',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => ApprovalRequest::class,
            'subject_id' => $approvalRequest->id,
            'properties' => [
                'experiment_id' => $approvalRequest->experiment_id,
                'workflow_node_id' => $approvalRequest->workflow_node_id,
                'notes' => $notes,
            ],
            'created_at' => now(),
        ]);

        Log::info('CompleteHumanTaskAction: Human task completed', [
            'approval_id' => $approvalRequest->id,
            'step_id' => $step?->id,
            'reviewer' => $reviewerId,
        ]);
    }

    /**
     * Resume an agent execution interrupted for clarification.
     * Transitions the experiment back to Executing and re-dispatches the playbook step job
     * with the operator's answer injected as input['clarification_answer'].
     */
    private function completeClarificationRequest(
        ApprovalRequest $approvalRequest,
        array $formResponse,
        string $reviewerId,
        ?string $notes,
    ): void {
        $approvalRequest->update([
            'status' => ApprovalStatus::Approved,
            'form_response' => $formResponse,
            'reviewed_by' => $reviewerId,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        $ocsf = OcsfMapper::classify('clarification.completed');
        AuditEntry::withoutGlobalScopes()->create([
            'user_id' => $reviewerId,
            'team_id' => $approvalRequest->team_id,
            'event' => 'clarification.completed',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => ApprovalRequest::class,
            'subject_id' => $approvalRequest->id,
            'properties' => [
                'experiment_id' => $approvalRequest->experiment_id,
                'step_id' => $approvalRequest->context['step_id'] ?? null,
                'answer_length' => strlen($formResponse['answer'] ?? ''),
            ],
            'created_at' => now(),
        ]);

        $experiment = $approvalRequest->experiment_id
            ? Experiment::withoutGlobalScopes()->find($approvalRequest->experiment_id)
            : null;

        if ($experiment) {
            try {
                // AwaitingApproval → Approved → Executing (standard approval path)
                $this->transition->execute(
                    experiment: $experiment,
                    toState: ExperimentStatus::Approved,
                    reason: 'Clarification provided by operator',
                    actorId: $reviewerId,
                );

                $experiment = Experiment::withoutGlobalScopes()->find($experiment->id);
                $this->transition->execute(
                    experiment: $experiment,
                    toState: ExperimentStatus::Executing,
                    reason: 'Resuming execution after clarification',
                );
            } catch (\Throwable $e) {
                Log::warning('CompleteHumanTaskAction: failed to transition experiment after clarification', [
                    'experiment_id' => $experiment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Re-dispatch the step with the clarification answer merged into input
        $stepId = $approvalRequest->context['step_id'] ?? null;
        $originalInput = $approvalRequest->context['original_input'] ?? [];

        if ($stepId) {
            ExecutePlaybookStepJob::dispatch(
                stepId: $stepId,
                experimentId: $approvalRequest->experiment_id,
                teamId: $approvalRequest->team_id,
                inputOverrides: array_merge($originalInput, ['clarification_answer' => $formResponse['answer'] ?? '']),
            );
        }

        Log::info('CompleteHumanTaskAction: clarification completed, step re-dispatched', [
            'approval_id' => $approvalRequest->id,
            'step_id' => $stepId,
            'experiment_id' => $approvalRequest->experiment_id,
        ]);
    }

    private function continueWorkflow(PlaybookStep $step): void
    {
        $experiment = $step->experiment;

        if (! $experiment) {
            return;
        }

        try {
            app(WorkflowGraphExecutor::class)->execute($experiment);
        } catch (\Throwable $e) {
            Log::error('CompleteHumanTaskAction: Failed to continue workflow', [
                'step_id' => $step->id,
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
