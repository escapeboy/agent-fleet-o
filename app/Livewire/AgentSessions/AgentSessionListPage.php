<?php

namespace App\Livewire\AgentSessions;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AgentSessionListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $agentFilter = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAgentFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = AgentSession::query()
            ->with('agent')
            ->withCount('events');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->agentFilter !== '') {
            $query->where('agent_id', $this->agentFilter);
        }

        $query->orderByDesc('last_heartbeat_at')->orderByDesc('created_at');

        return view('livewire.agent-sessions.agent-session-list-page', [
            'sessions' => $query->paginate(20),
            'statuses' => AgentSessionStatus::cases(),
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['header' => 'Agent Sessions']);
    }
}
