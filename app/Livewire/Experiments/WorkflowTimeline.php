<?php

namespace App\Livewire\Experiments;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Models\WorkflowSnapshot;
use App\Mcp\Tools\Agent\AgentDryRunTool;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Livewire\Component;

class WorkflowTimeline extends Component
{
    public string $experimentId;

    public ?array $selectedSnapshot = null;

    /** Snapshot ID currently in the replay modal (empty when modal is closed). */
    public string $replayingFor = '';

    /** Agent ID resolved from the playbook step. Null when no agent is bound. */
    public ?string $replayAgentId = null;

    public string $replayInput = '';

    public string $replaySystemPromptOverride = '';

    /** @var array<string, mixed>|null */
    public ?array $replayResult = null;

    public string $replayError = '';

    public bool $replayInFlight = false;

    public function mount(string $experimentId): void
    {
        $this->experimentId = $experimentId;
    }

    public function selectSnapshot(string $snapshotId): void
    {
        $snapshot = WorkflowSnapshot::find($snapshotId);

        if ($snapshot && $snapshot->experiment_id === $this->experimentId) {
            $this->selectedSnapshot = [
                'id' => $snapshot->id,
                'event_type' => $snapshot->event_type,
                'sequence' => $snapshot->sequence,
                'graph_state' => $snapshot->graph_state,
                'step_input' => $snapshot->step_input,
                'step_output' => $snapshot->step_output,
                'metadata' => $snapshot->metadata,
                'duration_from_start_ms' => $snapshot->duration_from_start_ms,
                'created_at' => $snapshot->created_at->toIso8601String(),
                'playbook_step_id' => $snapshot->playbook_step_id,
            ];
        }
    }

    public function clearSelection(): void
    {
        $this->selectedSnapshot = null;
        $this->closeReplay();
    }

    /**
     * Open the replay modal for the given snapshot. Resolves the agent from
     * the snapshot's playbook step and pre-fills the input box from the
     * snapshot's step_input when a clear "user_message" key is present.
     */
    public function openReplay(string $snapshotId): void
    {
        $this->selectSnapshot($snapshotId);
        if (! $this->selectedSnapshot) {
            return;
        }

        $playbookStepId = $this->selectedSnapshot['playbook_step_id'] ?? null;
        if ($playbookStepId === null) {
            $this->replayError = 'This snapshot has no playbook step — replay is unavailable.';

            return;
        }

        $step = PlaybookStep::find($playbookStepId);
        if (! $step || ! $step->agent_id) {
            $this->replayError = 'No agent is bound to this step.';

            return;
        }

        $this->replayingFor = $snapshotId;
        $this->replayAgentId = $step->agent_id;
        $this->replayInput = $this->derivePrefilledInput($this->selectedSnapshot['step_input'] ?? []);
        $this->replaySystemPromptOverride = '';
        $this->replayResult = null;
        $this->replayError = '';
        $this->replayInFlight = false;
    }

    public function closeReplay(): void
    {
        $this->replayingFor = '';
        $this->replayAgentId = null;
        $this->replayInput = '';
        $this->replaySystemPromptOverride = '';
        $this->replayResult = null;
        $this->replayError = '';
        $this->replayInFlight = false;
    }

    public function executeReplay(): void
    {
        if ($this->replayingFor === '' || $this->replayAgentId === null) {
            return;
        }

        $input = trim($this->replayInput);
        if ($input === '') {
            $this->replayError = 'Input message cannot be empty.';

            return;
        }

        $this->replayInFlight = true;
        $this->replayError = '';
        $this->replayResult = null;

        try {
            $teamId = auth()->user()?->current_team_id;
            if ($teamId !== null) {
                app()->instance('mcp.team_id', $teamId);
            }

            $tool = app(AgentDryRunTool::class);
            $args = [
                'agent_id' => $this->replayAgentId,
                'input_message' => $input,
            ];
            $override = trim($this->replaySystemPromptOverride);
            if ($override !== '') {
                $args['system_prompt_override'] = $override;
            }

            $response = $tool->handle(new Request($args));
            $payload = json_decode((string) $response->content(), true);

            if ($response->isError()) {
                $this->replayError = is_array($payload) && isset($payload['error']['message'])
                    ? (string) $payload['error']['message']
                    : 'Replay failed.';

                return;
            }

            $this->replayResult = is_array($payload) ? $payload : null;

            $this->recordReplayAudit();
            $this->persistReplayHistory();
        } catch (\Throwable $e) {
            Log::warning('WorkflowTimeline: replay failed', [
                'experiment_id' => $this->experimentId,
                'snapshot_id' => $this->replayingFor,
                'agent_id' => $this->replayAgentId,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);
            $this->replayError = 'Replay threw '.class_basename($e).'.';
        } finally {
            $this->replayInFlight = false;
        }
    }

    /**
     * Record the replay invocation in the team-scoped audit log so support
     * can trace who replayed which snapshot and what the model returned.
     */
    private function recordReplayAudit(): void
    {
        try {
            $experiment = Experiment::find($this->experimentId);
            if (! $experiment) {
                return;
            }
            $ocsf = OcsfMapper::classify('experiment.replay');
            AuditEntry::create([
                'team_id' => $experiment->team_id,
                'user_id' => optional(auth()->user())->id,
                'event' => 'experiment.replay',
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'subject_type' => WorkflowSnapshot::class,
                'subject_id' => $this->replayingFor,
                'properties' => [
                    'experiment_id' => $this->experimentId,
                    'agent_id' => $this->replayAgentId,
                    'override_used' => trim($this->replaySystemPromptOverride) !== '',
                    'input_length' => mb_strlen($this->replayInput),
                    'cost_credits' => $this->replayResult['cost_credits'] ?? null,
                    'latency_ms' => $this->replayResult['latency_ms'] ?? null,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit failures must not break the replay flow.
        }
    }

    /**
     * Append the latest replay result to WorkflowSnapshot.metadata.replays
     * (capped at the last N entries so the JSONB cell doesn't grow without
     * bound). Lets users see prior replays when re-opening the modal.
     */
    private function persistReplayHistory(): void
    {
        if ($this->replayingFor === '' || ! is_array($this->replayResult)) {
            return;
        }

        try {
            $snapshot = WorkflowSnapshot::find($this->replayingFor);
            if (! $snapshot || $snapshot->experiment_id !== $this->experimentId) {
                return;
            }

            $metadata = is_array($snapshot->metadata) ? $snapshot->metadata : [];
            $history = $metadata['replays'] ?? [];
            if (! is_array($history)) {
                $history = [];
            }

            array_unshift($history, [
                'at' => now()->toIso8601String(),
                'user_id' => optional(auth()->user())->id,
                'agent_id' => $this->replayAgentId,
                'override_used' => trim($this->replaySystemPromptOverride) !== '',
                'output' => mb_substr((string) ($this->replayResult['output'] ?? ''), 0, 2000),
                'cost_credits' => $this->replayResult['cost_credits'] ?? null,
                'latency_ms' => $this->replayResult['latency_ms'] ?? null,
                'tokens_input' => $this->replayResult['tokens_input'] ?? null,
                'tokens_output' => $this->replayResult['tokens_output'] ?? null,
                'model' => $this->replayResult['model'] ?? null,
                'provider' => $this->replayResult['provider'] ?? null,
            ]);

            $cap = (int) config('self-service.diagnose.replay_history_cap', 5);
            $history = array_slice($history, 0, max(1, $cap));

            $metadata['replays'] = $history;
            $snapshot->update(['metadata' => $metadata]);

            // Refresh the in-memory selectedSnapshot so the modal can render
            // the updated history without a full page reload.
            if ($this->selectedSnapshot && ($this->selectedSnapshot['id'] ?? null) === $snapshot->id) {
                $this->selectedSnapshot['metadata'] = $metadata;
            }
        } catch (\Throwable $e) {
            Log::warning('WorkflowTimeline: failed to persist replay history', [
                'snapshot_id' => $this->replayingFor,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $stepInput
     */
    private function derivePrefilledInput(array $stepInput): string
    {
        if (isset($stepInput['user_message']) && is_string($stepInput['user_message'])) {
            return $stepInput['user_message'];
        }
        if (isset($stepInput['input']) && is_string($stepInput['input'])) {
            return $stepInput['input'];
        }
        if (isset($stepInput['prompt']) && is_string($stepInput['prompt'])) {
            return $stepInput['prompt'];
        }

        // Fall back to a JSON-pretty dump capped at 2KB so the textarea isn't unusable.
        $encoded = json_encode($stepInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? mb_substr($encoded, 0, 2000) : '';
    }

    public function render()
    {
        $snapshots = WorkflowSnapshot::where('experiment_id', $this->experimentId)
            ->orderBy('sequence')
            ->get();

        return view('livewire.experiments.workflow-timeline', [
            'snapshots' => $snapshots,
        ]);
    }
}
