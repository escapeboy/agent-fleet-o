<?php

namespace App\Livewire\Experiments;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Illuminate\Support\Str;
use Livewire\Component;

class ExecutionLogPanel extends Component
{
    public string $experimentId;

    public ?string $expandedEventId = null;

    /** ID of the ApprovalRequest currently being rejected (drives modal visibility). */
    public string $rejectingApprovalId = '';

    /** Rejection reason text entered by the user in the modal. */
    public string $rejectReason = '';

    public function toggleEvent(string $eventId): void
    {
        $this->expandedEventId = $this->expandedEventId === $eventId ? null : $eventId;
    }

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

    public function render()
    {
        $events = collect();

        // State transitions
        $transitions = ExperimentStateTransition::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => 'transition-'.$t->id,
                'type' => 'transition',
                'time' => $t->created_at,
                'summary' => "{$t->from_state} -> {$t->to_state}",
                'detail' => $t->reason,
                'metadata' => $t->metadata,
                'icon' => 'arrow-right',
                'color' => str_contains($t->to_state, 'failed') ? 'red' : (str_contains($t->to_state, 'completed') ? 'green' : 'blue'),
            ]);
        $events = $events->merge($transitions);

        // Experiment stages
        $stages = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ExperimentStage $s) => [
                'id' => 'stage-'.$s->id,
                'type' => 'stage',
                'time' => $s->started_at ?? $s->created_at,
                'summary' => "Stage: {$s->stage->value} ({$s->status->value})",
                'detail' => $s->duration_ms ? round($s->duration_ms / 1000, 1).'s' : null,
                'metadata' => null,
                'icon' => 'cog',
                'color' => match ($s->status->value) {
                    'completed' => 'green',
                    'failed' => 'red',
                    'running' => 'blue',
                    default => 'gray',
                },
            ]);
        $events = $events->merge($stages);

        // Playbook steps
        $steps = PlaybookStep::where('experiment_id', $this->experimentId)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->with('agent')
            ->limit(200)
            ->get()
            ->map(fn (PlaybookStep $s) => [
                'id' => 'step-'.$s->id,
                'type' => 'step',
                'time' => $s->started_at,
                'summary' => "Step #{$s->order}: ".($s->agent?->name ?? 'Unknown agent')." ({$s->status})",
                'detail' => $s->error_message ?? ($s->duration_ms ? round($s->duration_ms / 1000, 1).'s, '.($s->cost_credits ?? 0).' credits' : null),
                'metadata' => null,
                'icon' => 'play',
                'color' => match ($s->status) {
                    'completed' => 'green',
                    'failed' => 'red',
                    'running' => 'blue',
                    default => 'gray',
                },
            ]);
        $events = $events->merge($steps);

        // LLM calls (cap at 500 to prevent unbounded memory on long-running experiments)
        $llmLogs = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->limit(500)
            ->get()
            ->map(fn (LlmRequestLog $l) => [
                'id' => 'llm-'.$l->id,
                'type' => 'llm_call',
                'time' => $l->created_at,
                'summary' => "LLM: {$l->provider}/{$l->model} ({$l->status})",
                'detail' => collect([
                    $l->input_tokens ? "{$l->input_tokens} in" : null,
                    $l->output_tokens ? "{$l->output_tokens} out" : null,
                    $l->cost_credits ? "{$l->cost_credits} credits" : null,
                    $l->latency_ms ? round($l->latency_ms / 1000, 1).'s' : null,
                ])->filter()->implode(', '),
                'metadata' => $l->error ? ['error' => $l->error] : null,
                'icon' => 'chip',
                'color' => match ($l->status) {
                    'completed', 'success' => 'green',
                    'failed', 'error' => 'red',
                    default => 'gray',
                },
            ]);
        $events = $events->merge($llmLogs);

        // ApprovalRequest events — shown as inline approval cards
        $approvalEvents = ApprovalRequest::where('experiment_id', $this->experimentId)
            ->with('reviewer')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ApprovalRequest $approval) => [
                'id' => 'approval-'.$approval->id,
                'approval_id' => $approval->id,
                'type' => 'approval',
                'time' => $approval->created_at,
                'summary' => 'Approval required: '.Str::limit($approval->context['message'] ?? 'Human review needed', 80),
                'detail' => json_encode($approval->context ?? []),
                'metadata' => null,
                'icon' => 'clock',
                'color' => match ($approval->status) {
                    ApprovalStatus::Pending => 'amber',
                    ApprovalStatus::Approved => 'green',
                    ApprovalStatus::Rejected => 'red',
                    default => 'gray',
                },
                'status' => $approval->status->value,
                'reviewer' => $approval->reviewer?->name,
                'reviewed_at' => $approval->reviewed_at,
            ]);
        $events = $events->merge($approvalEvents);

        return view('livewire.experiments.execution-log-panel', [
            'events' => $events->sortBy('time')->values(),
        ]);
    }
}
