<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Tools\Experiment\ExperimentDiagnoseTool;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Failure-context "🔍 Diagnose" + "💬 Ask Assistant" surface.
 *
 * Mounts under failed/paused detail pages (experiments today, more later)
 * and asks the matching diagnose MCP tool to translate the technical
 * failure into a customer-readable summary plus a list of recommended
 * recovery actions.
 *
 * The component itself only triggers the diagnosis and renders. Action
 * execution is delegated to the parent page (route nav, tool calls, or
 * assistant prompts) via dispatched events / regular links.
 */
class FixWithAssistant extends Component
{
    /** Currently only 'experiment' supported in P0. */
    #[Locked]
    public string $entityType = '';

    #[Locked]
    public string $entityId = '';

    /** When false, the component renders nothing. Set in mount based on entity state. */
    public bool $eligible = false;

    /** Last diagnosis payload (rendered when present). */
    public ?array $diagnosis = null;

    /** Indicates we already attempted diagnosis (avoids re-running on re-render). */
    public bool $diagnosed = false;

    public string $errorMessage = '';

    public function mount(string $entityType, string $entityId): void
    {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->eligible = $this->resolveEligibility();
    }

    public function diagnose(): void
    {
        if (! $this->eligible) {
            return;
        }

        $this->errorMessage = '';
        $this->diagnosed = true;

        try {
            $tool = app(ExperimentDiagnoseTool::class);
            $response = $tool->handle(new Request([
                'experiment_id' => $this->entityId,
                'locale' => app()->getLocale(),
            ]));

            $payload = json_decode((string) $response->content(), true);

            if ($response->isError()) {
                $this->errorMessage = is_array($payload) && isset($payload['error']['message'])
                    ? (string) $payload['error']['message']
                    : 'Diagnosis failed.';

                return;
            }

            $this->diagnosis = is_array($payload) ? $payload : null;
        } catch (\Throwable $e) {
            Log::warning('FixWithAssistant: diagnose failed', [
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'error' => $e->getMessage(),
            ]);
            $this->errorMessage = 'We could not diagnose this issue right now. Please try again or open the assistant manually.';
        }
    }

    /**
     * Open the assistant panel with the given prompt prefilled. Triggered by
     * an action of kind=assistant (e.g. "Ask assistant to investigate").
     */
    public function askAssistant(string $prompt): void
    {
        // The assistant panel listens for 'open-assistant' on window (browser event)
        // and pre-fills the input.
        $this->dispatch('open-assistant', message: $prompt);
    }

    /**
     * Direct execution path for safe-tier `tool` recovery actions, bypassing
     * the assistant for one-click recovery (P1 inline recovery work).
     *
     * Only tool names on the explicit allowlist are honored, and only safe-tier
     * (non-config, non-destructive) actions reach this method from the UI.
     * config / destructive tier still funnel through the assistant for the
     * existing role-tier gating.
     *
     * @param  array<string, mixed>  $params
     */
    public function executeRecoveryAction(string $toolName, array $params = []): void
    {
        if (! $this->eligible) {
            return;
        }

        // Defense-in-depth: explicit allowlist of safe-tier MCP tools that
        // self-service customers can run from the diagnose card. Adding to this
        // list MUST be done together with the dictionary 'tier' => 'safe'.
        $allowed = [
            'experiment_retry' => \App\Mcp\Tools\Experiment\ExperimentRetryTool::class,
            'experiment_resume' => \App\Mcp\Tools\Experiment\ExperimentResumeTool::class,
            'experiment_resume_from_checkpoint' => \App\Mcp\Tools\Experiment\ExperimentResumeFromCheckpointTool::class,
        ];

        if (! isset($allowed[$toolName])) {
            $this->errorMessage = 'This action is not allowlisted for one-click execution.';

            return;
        }

        // The recommended action only operates on the entity in scope.
        // Forcing experiment_id to the bound entity prevents replay against
        // an unrelated experiment if the params payload were tampered with.
        if (isset($params['experiment_id'])) {
            $params['experiment_id'] = $this->entityId;
        }

        try {
            // Bind mcp.team_id so the underlying tool's tenant resolution
            // works without relying on auth() during a Livewire request.
            $teamId = auth()->user()?->current_team_id;
            if ($teamId !== null) {
                app()->instance('mcp.team_id', $teamId);
            }

            /** @var \Laravel\Mcp\Server\Tool $tool */
            $tool = app($allowed[$toolName]);
            $response = $tool->handle(new \Laravel\Mcp\Request($params));

            if ($response->isError()) {
                $payload = json_decode((string) $response->content(), true);
                $this->errorMessage = is_array($payload) && isset($payload['error']['message'])
                    ? (string) $payload['error']['message']
                    : 'Recovery action failed.';

                return;
            }

            // Success — clear the diagnosis card and notify the parent page.
            $this->dispatch('notify', message: 'Recovery action executed.', type: 'success');
            $this->dispatch('experiment-recovered', toolName: $toolName);
            $this->diagnosis = null;
            $this->diagnosed = false;
            $this->errorMessage = '';

            // Re-evaluate eligibility — the experiment may no longer be failed.
            $this->eligible = $this->resolveEligibility();
        } catch (\Throwable $e) {
            Log::warning('FixWithAssistant: recovery action failed', [
                'tool' => $toolName,
                'entity_id' => $this->entityId,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);
            // Surface the exception class to help support agents triage; the
            // raw message can leak internal details so we keep it server-side.
            $this->errorMessage = 'Recovery action threw '.class_basename($e).'.';
        }
    }

    public function render(): View
    {
        return view('livewire.shared.fix-with-assistant');
    }

    /**
     * Decide whether to surface the component for the given entity. A
     * mismatched/missing entity simply renders nothing.
     */
    private function resolveEligibility(): bool
    {
        if ($this->entityType !== 'experiment' || $this->entityId === '') {
            return false;
        }

        // TeamScope active in web context — find() respects tenant isolation.
        $experiment = Experiment::find($this->entityId);
        if (! $experiment) {
            return false;
        }

        $status = $experiment->status;

        return $status->isFailed() || $status->value === 'paused';
    }
}
