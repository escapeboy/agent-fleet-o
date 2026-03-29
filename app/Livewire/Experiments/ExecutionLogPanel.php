<?php

namespace App\Livewire\Experiments;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class ExecutionLogPanel extends Component
{
    public string $experimentId;

    /** ID of the ApprovalRequest currently being rejected (drives modal visibility). */
    public string $rejectingApprovalId = '';

    /** Rejection reason text entered by the user in the modal. */
    public string $rejectReason = '';

    /**
     * Approve the given pending ApprovalRequest inline.
     */
    public function approveInline(string $approvalId): void
    {
        $this->authorize('edit-content');

        $approval = ApprovalRequest::where('experiment_id', $this->experimentId)
            ->findOrFail($approvalId);

        app(ApproveAction::class)->execute($approval, auth()->id());

        $this->dispatch('notify', type: 'success', message: 'Approved successfully.');
    }

    /**
     * Open the rejection reason modal for the given ApprovalRequest.
     */
    public function openRejectModal(string $approvalId): void
    {
        $this->rejectingApprovalId = $approvalId;
        $this->rejectReason = '';
    }

    /**
     * Submit the rejection with the entered reason.
     */
    public function confirmReject(): void
    {
        $this->authorize('edit-content');
        $this->validate(['rejectReason' => 'required|min:10']);

        $approval = ApprovalRequest::where('experiment_id', $this->experimentId)
            ->findOrFail($this->rejectingApprovalId);

        app(RejectAction::class)->execute($approval, auth()->id(), $this->rejectReason);

        $this->rejectingApprovalId = '';
        $this->rejectReason = '';

        $this->dispatch('notify', type: 'success', message: 'Rejected.');
    }

    public function render(): View
    {
        // Load all stages ordered by start time
        $stages = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('started_at')
            ->orderBy('created_at')
            ->get();

        // Load all state transitions
        $allTransitions = ExperimentStateTransition::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get();

        // Load all playbook steps
        $allSteps = PlaybookStep::where('experiment_id', $this->experimentId)
            ->orderBy('started_at')
            ->with('agent')
            ->limit(200)
            ->get();

        // Load LLM calls grouped by stage (they have experiment_stage_id)
        $allLlmLogs = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->limit(500)
            ->get()
            ->groupBy('experiment_stage_id');

        // Load all approvals
        $allApprovals = ApprovalRequest::where('experiment_id', $this->experimentId)
            ->with('reviewer')
            ->orderBy('created_at')
            ->get();

        $blocks = collect();

        if ($stages->isEmpty()) {
            // No stages yet — single flat block with everything
            $preBlock = $this->buildPreExecutionBlock(
                $allTransitions,
                $allSteps,
                $allLlmLogs->get(null, collect()),
                $allApprovals,
            );
            if ($preBlock['transitions']->isNotEmpty() || $preBlock['steps']->isNotEmpty() || $preBlock['llmCalls']->isNotEmpty() || $preBlock['approvals']->isNotEmpty()) {
                $blocks->push($preBlock);
            }
        } else {
            // Pre-execution block: transitions/steps/approvals before the first stage started
            $firstStageStart = $stages->first()->started_at ?? $stages->first()->created_at;

            $preTransitions = $allTransitions->filter(
                fn ($t) => $t->created_at < $firstStageStart,
            )->values();

            $preSteps = $allSteps->filter(
                fn ($s) => $s->started_at && $s->started_at < $firstStageStart,
            )->values();

            $preApprovals = $allApprovals->filter(
                fn ($a) => $a->created_at < $firstStageStart,
            )->values();

            $preLlmCalls = $allLlmLogs->get(null, collect())
                ->filter(fn ($l) => $l->created_at < $firstStageStart)
                ->values();

            if ($preTransitions->isNotEmpty() || $preSteps->isNotEmpty() || $preApprovals->isNotEmpty() || $preLlmCalls->isNotEmpty()) {
                $blocks->push($this->buildPreExecutionBlock($preTransitions, $preSteps, $preLlmCalls, $preApprovals));
            }

            // One block per stage
            foreach ($stages as $stage) {
                $stageStart = $stage->started_at ?? $stage->created_at;
                $stageEnd = $stage->completed_at;

                // Transitions within this stage's time window
                $stageTransitions = $allTransitions->filter(function ($t) use ($stageStart, $stageEnd) {
                    return $t->created_at >= $stageStart
                        && ($stageEnd === null || $t->created_at <= $stageEnd);
                })->values();

                // Steps within this stage's time window
                $stageSteps = $allSteps->filter(function ($s) use ($stageStart, $stageEnd) {
                    if (! $s->started_at) {
                        return false;
                    }

                    return $s->started_at >= $stageStart
                        && ($stageEnd === null || $s->started_at <= $stageEnd);
                })->values();

                // LLM calls linked directly to this stage
                $stageLlmCalls = $allLlmLogs->get($stage->id, collect())->values();

                // Approvals within this stage's time window
                $stageApprovals = $allApprovals->filter(function ($a) use ($stageStart, $stageEnd) {
                    return $a->created_at >= $stageStart
                        && ($stageEnd === null || $a->created_at <= $stageEnd);
                })->values();

                $totalTokens = $stageLlmCalls->sum('input_tokens') + $stageLlmCalls->sum('output_tokens');
                $totalCost = $stageLlmCalls->sum('cost_credits');
                $durationSeconds = $stage->duration_ms ? round($stage->duration_ms / 1000, 1) : null;

                $blocks->push([
                    'id' => 'stage-'.$stage->id,
                    'type' => 'stage',
                    'stage' => $stage,
                    'transitions' => $stageTransitions,
                    'steps' => $stageSteps,
                    'llmCalls' => $stageLlmCalls,
                    'approvals' => $stageApprovals,
                    'summary' => [
                        'tokens' => $totalTokens,
                        'cost' => $totalCost,
                        'duration_seconds' => $durationSeconds,
                    ],
                ]);
            }

            // Trailing block: events after the last completed stage
            $lastStageEnd = $stages->last()->completed_at;
            if ($lastStageEnd) {
                $trailingTransitions = $allTransitions->filter(
                    fn ($t) => $t->created_at > $lastStageEnd,
                )->values();

                $trailingSteps = $allSteps->filter(
                    fn ($s) => $s->started_at && $s->started_at > $lastStageEnd,
                )->values();

                $trailingApprovals = $allApprovals->filter(
                    fn ($a) => $a->created_at > $lastStageEnd,
                )->values();

                $trailingLlmCalls = $allLlmLogs->get(null, collect())
                    ->filter(fn ($l) => $l->created_at > $lastStageEnd)
                    ->values();

                if ($trailingTransitions->isNotEmpty() || $trailingSteps->isNotEmpty() || $trailingApprovals->isNotEmpty() || $trailingLlmCalls->isNotEmpty()) {
                    $blocks->push($this->buildPreExecutionBlock($trailingTransitions, $trailingSteps, $trailingLlmCalls, $trailingApprovals, 'Post-execution'));
                }
            }
        }

        $hasFailedBlock = $blocks->contains(fn ($b) => $b['type'] === 'stage' && $b['stage']->status->value === 'failed');

        return view('livewire.experiments.execution-log-panel', [
            'blocks' => $blocks,
            'hasFailedBlock' => $hasFailedBlock,
        ]);
    }

    /**
     * Build a pre/post execution block for events outside any stage window.
     */
    private function buildPreExecutionBlock(
        Collection $transitions,
        Collection $steps,
        Collection $llmCalls,
        Collection $approvals,
        string $label = 'Pre-execution',
    ): array {
        $totalTokens = $llmCalls->sum('input_tokens') + $llmCalls->sum('output_tokens');
        $totalCost = $llmCalls->sum('cost_credits');

        return [
            'id' => 'pre-execution-'.Str::random(6),
            'type' => 'pre_execution',
            'label' => $label,
            'stage' => null,
            'transitions' => $transitions,
            'steps' => $steps,
            'llmCalls' => $llmCalls,
            'approvals' => $approvals,
            'summary' => [
                'tokens' => $totalTokens,
                'cost' => $totalCost,
                'duration_seconds' => null,
            ],
        ];
    }
}
