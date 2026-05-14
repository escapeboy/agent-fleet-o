<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Log;

class CreateHumanTaskAction
{
    public function __construct(
        private readonly WorkflowGraphExecutor $graphExecutor,
    ) {}

    public function execute(
        Experiment $experiment,
        PlaybookStep $step,
        WorkflowNode $node,
    ): ApprovalRequest {
        $config = is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []);

        $slaHours = $config['sla_hours'] ?? 48;
        $assignmentPolicy = $config['assignment_policy'] ?? 'any_team_member';
        $escalationChain = $config['escalation_chain'] ?? null;

        // Borrowed from prilog.ai: optionally bypass the human gate when the upstream
        // agent reports high confidence. The approval record is still written for audit.
        $skipDecision = $this->evaluateSkipIfTrusted($experiment, $step, $config);

        $status = $skipDecision['skip'] ? ApprovalStatus::Approved : ApprovalStatus::Pending;
        $reviewedAt = $skipDecision['skip'] ? now() : null;
        $context = [
            'experiment_title' => $experiment->title,
            'step_id' => $step->id,
            'node_label' => $node->label,
            'node_type' => 'human_task',
            'instructions' => $config['prompt'] ?? null,
        ];

        if ($skipDecision['skip']) {
            $context['auto_approved'] = true;
            $context['auto_approval_reason'] = $skipDecision['reason'];
            $context['confidence_observed'] = $skipDecision['confidence'];
            $context['confidence_threshold'] = $skipDecision['threshold'];
        }

        $step->update(['status' => $skipDecision['skip'] ? 'completed' : 'waiting_human']);

        $approvalRequest = ApprovalRequest::withoutGlobalScopes()->create([
            'experiment_id' => $experiment->id,
            'team_id' => $experiment->team_id,
            'workflow_node_id' => $node->id,
            'status' => $status,
            'form_schema' => $config['form_schema'] ?? null,
            'assignment_policy' => $assignmentPolicy,
            'sla_deadline' => now()->addHours($slaHours),
            'escalation_chain' => $escalationChain,
            'escalation_level' => 0,
            'expires_at' => now()->addHours($slaHours * 2),
            'reviewed_at' => $reviewedAt,
            'reviewer_notes' => $skipDecision['skip'] ? $skipDecision['reason'] : null,
            'context' => $context,
        ]);

        if ($skipDecision['skip']) {
            $step->update([
                'output' => [
                    'auto_approved' => true,
                    'reason' => $skipDecision['reason'],
                    'confidence_observed' => $skipDecision['confidence'],
                ],
                'completed_at' => now(),
            ]);

            $this->logAutoApproval($approvalRequest, $skipDecision);
            $this->continueWorkflow($experiment);
        }

        return $approvalRequest;
    }

    /**
     * Decide whether to bypass the human gate. Returns:
     *   ['skip' => bool, 'reason' => ?string, 'confidence' => ?float, 'threshold' => ?float]
     *
     * Skip requires:
     *   - config.skip_if_trusted === true
     *   - config.confidence_threshold is a numeric value between 0 and 1
     *   - the upstream node identified by config.confidence_source_node_id has produced
     *     a numeric `confidence` field in its output
     *   - observed confidence >= threshold
     *
     * @param  array<string, mixed>  $config
     * @return array{skip: bool, reason: ?string, confidence: ?float, threshold: ?float}
     */
    private function evaluateSkipIfTrusted(Experiment $experiment, PlaybookStep $step, array $config): array
    {
        $default = ['skip' => false, 'reason' => null, 'confidence' => null, 'threshold' => null];

        if (empty($config['skip_if_trusted'])) {
            return $default;
        }

        $threshold = $config['confidence_threshold'] ?? null;
        if (! is_numeric($threshold) || $threshold < 0.0 || $threshold > 1.0) {
            return $default;
        }
        $threshold = (float) $threshold;

        $sourceNodeId = $config['confidence_source_node_id'] ?? null;
        if (! is_string($sourceNodeId) || $sourceNodeId === '') {
            return $default;
        }

        $sourceStep = PlaybookStep::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('workflow_node_id', $sourceNodeId)
            ->orderByDesc('completed_at')
            ->first();

        if (! $sourceStep || empty($sourceStep->output)) {
            return $default;
        }

        $output = is_array($sourceStep->output) ? $sourceStep->output : [];
        $confidence = $output['confidence'] ?? null;

        if (! is_numeric($confidence)) {
            return $default;
        }
        $confidence = (float) $confidence;

        if ($confidence < $threshold) {
            return [
                'skip' => false,
                'reason' => "Upstream confidence {$confidence} below threshold {$threshold}",
                'confidence' => $confidence,
                'threshold' => $threshold,
            ];
        }

        return [
            'skip' => true,
            'reason' => "Auto-approved: upstream confidence {$confidence} >= threshold {$threshold}",
            'confidence' => $confidence,
            'threshold' => $threshold,
        ];
    }

    /**
     * @param  array{skip: bool, reason: ?string, confidence: ?float, threshold: ?float}  $decision
     */
    private function logAutoApproval(ApprovalRequest $approvalRequest, array $decision): void
    {
        try {
            $ocsf = OcsfMapper::classify('human_task.auto_approved');
            AuditEntry::withoutGlobalScopes()->create([
                'team_id' => $approvalRequest->team_id,
                'event' => 'human_task.auto_approved',
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'subject_type' => ApprovalRequest::class,
                'subject_id' => $approvalRequest->id,
                'properties' => [
                    'experiment_id' => $approvalRequest->experiment_id,
                    'workflow_node_id' => $approvalRequest->workflow_node_id,
                    'confidence' => $decision['confidence'],
                    'threshold' => $decision['threshold'],
                    'reason' => $decision['reason'],
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CreateHumanTaskAction: failed to write audit entry for auto-approval', [
                'approval_id' => $approvalRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function continueWorkflow(Experiment $experiment): void
    {
        try {
            $this->graphExecutor->execute($experiment);
        } catch (\Throwable $e) {
            Log::error('CreateHumanTaskAction: failed to continue workflow after auto-approval', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
