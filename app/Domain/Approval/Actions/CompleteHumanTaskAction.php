<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CompleteHumanTaskAction
{
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

        AuditEntry::withoutGlobalScopes()->create([
            'user_id' => $reviewerId,
            'team_id' => $approvalRequest->team_id,
            'event' => 'human_task.completed',
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
