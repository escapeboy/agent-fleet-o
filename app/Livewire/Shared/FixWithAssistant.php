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
