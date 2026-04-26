<?php

namespace App\Livewire\Experiments;

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
