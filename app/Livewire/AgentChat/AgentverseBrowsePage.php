<?php

declare(strict_types=1);

namespace App\Livewire\AgentChat;

use App\Domain\AgentChatProtocol\Actions\InstallFromAgentverseAction;
use App\Domain\AgentChatProtocol\Services\AgentverseClient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class AgentverseBrowsePage extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    public ?array $agents = null;

    public ?string $errorMessage = null;

    public bool $credentialMissing = false;

    public function mount(): void
    {
        $this->refreshAgents();
    }

    public function updatedSearch(): void
    {
        $this->refreshAgents();
    }

    public function updatedCategory(): void
    {
        $this->refreshAgents();
    }

    public function refreshAgents(): void
    {
        $teamId = (string) auth()->user()?->current_team_id;
        $client = AgentverseClient::forTeam($teamId);

        if ($client === null) {
            $this->credentialMissing = true;
            $this->agents = null;

            return;
        }

        $this->credentialMissing = false;
        $this->errorMessage = null;

        try {
            $filters = array_filter([
                'search' => $this->search,
                'category' => $this->category,
                'limit' => 50,
            ]);
            $this->agents = $client->listAgents($filters);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
            $this->agents = [];
        }
    }

    public function install(string $agentAddress, InstallFromAgentverseAction $action): void
    {
        $teamId = (string) auth()->user()?->current_team_id;

        try {
            $agent = $action->execute(teamId: $teamId, agentAddress: $agentAddress);
            session()->flash('success', "Installed: {$agent->name}");
            $this->redirect(route('external-agents.show', ['externalAgent' => $agent->id]));
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.agent-chat.agentverse-browse-page');
    }
}
