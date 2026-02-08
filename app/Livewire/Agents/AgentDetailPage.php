<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Skill\Models\Skill;
use Livewire\Component;

class AgentDetailPage extends Component
{
    public Agent $agent;
    public string $activeTab = 'overview';

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function toggleStatus(): void
    {
        $newStatus = $this->agent->status === AgentStatus::Active
            ? AgentStatus::Disabled
            : AgentStatus::Active;

        $this->agent->update(['status' => $newStatus]);
        $this->agent->refresh();
    }

    public function render()
    {
        $skills = $this->agent->skills()->get();

        $executions = AgentExecution::where('agent_id', $this->agent->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('livewire.agents.agent-detail-page', [
            'skills' => $skills,
            'executions' => $executions,
        ])->layout('layouts.app', ['header' => $this->agent->name]);
    }
}
