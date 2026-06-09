<?php

namespace App\Livewire\AgentSessions;

use App\Domain\AgentSession\Actions\CancelAgentSessionAction;
use App\Domain\AgentSession\Actions\ReplayAgentSessionAction;
use App\Domain\AgentSession\Actions\WakeAgentSessionAction;
use App\Domain\AgentSession\Models\AgentSession;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class AgentSessionDetailPage extends Component
{
    public string $sessionId;

    public bool $showCancelConfirm = false;

    public function mount(string $agentSession): void
    {
        // Route-model binding is avoided on purpose: a `public AgentSession`
        // property bound via `mount(AgentSession)` triggers a 22P02 cast error
        // when Livewire rehydrates the UUID. Resolve through a computed prop.
        $this->sessionId = $agentSession;

        // 404 if the session is outside the current team (TeamScope applies).
        abort_unless(AgentSession::whereKey($agentSession)->exists(), 404);
    }

    #[Computed]
    public function session(): AgentSession
    {
        return AgentSession::query()
            ->with('agent')
            ->withCount('events')
            ->findOrFail($this->sessionId);
    }

    public function wake(): void
    {
        Gate::authorize('edit-content');

        app(WakeAgentSessionAction::class)->execute(session: $this->session);

        unset($this->session);
        session()->flash('message', 'Session woken — recent context rehydrated.');
    }

    public function cancel(): void
    {
        Gate::authorize('edit-content');

        app(CancelAgentSessionAction::class)->execute(
            session: $this->session,
            reason: 'Cancelled from admin panel',
        );

        unset($this->session);
        $this->showCancelConfirm = false;
        session()->flash('message', 'Session cancelled.');
    }

    public function render()
    {
        $replay = app(ReplayAgentSessionAction::class)->execute($this->session);

        $events = $this->session->events()
            ->orderByDesc('seq')
            ->limit(500)
            ->get(['seq', 'kind', 'payload', 'created_at']);

        return view('livewire.agent-sessions.agent-session-detail-page', [
            'replay' => $replay,
            'events' => $events,
        ])->layout('layouts.app', ['header' => 'Agent Session']);
    }
}
