<?php

namespace App\Livewire\WorldModel;

use App\Domain\WorldModel\Jobs\BuildWorldModelDigestJob;
use App\Domain\WorldModel\Models\TeamWorldModel;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Shows the current team's auto-generated world-model digest — the briefing
 * that gets injected into every agent's system prompt (Sprint 3).
 *
 * Purpose: trust + transparency. Users should be able to see exactly what
 * the AI "knows" about their team before acting on a sensitive task.
 */
class WorldModelPage extends Component
{
    public bool $rebuilding = false;

    public function mount(): void
    {
        if (! auth()->user()?->currentTeam) {
            $this->redirect(route('dashboard'), navigate: true);
        }
    }

    /**
     * Queue a fresh digest build. Non-blocking — the job runs async and the
     * user refreshes to see the new digest.
     */
    public function rebuild(): void
    {
        $this->authorize('manage-team', auth()->user()->currentTeam);

        $teamId = auth()->user()->current_team_id;
        BuildWorldModelDigestJob::dispatch($teamId);
        $this->rebuilding = true;

        session()->flash('message', 'World-model rebuild queued. Refresh in ~30 seconds to see the fresh digest.');
    }

    public function render(): View
    {
        $teamId = auth()->user()->current_team_id;
        $model = TeamWorldModel::where('team_id', $teamId)->first();

        return view('livewire.world-model.world-model-page', [
            'model' => $model,
            'isStale' => $model?->isStale() ?? true,
            'canManage' => auth()->user()->can('manage-team', auth()->user()->currentTeam),
        ])->layout('layouts.app', ['header' => 'World Model']);
    }
}
