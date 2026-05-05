<?php

declare(strict_types=1);

namespace App\Livewire\AgentChat;

use App\Domain\AgentChatProtocol\Actions\RegisterExternalAgentAction;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ExternalAgentListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public bool $showRegisterModal = false;

    public string $newName = '';

    public string $newEndpointUrl = '';

    public string $newDescription = '';

    public function openRegister(): void
    {
        $this->showRegisterModal = true;
    }

    public function register(RegisterExternalAgentAction $action): void
    {
        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newEndpointUrl' => 'required|url|max:2048',
            'newDescription' => 'nullable|string|max:1000',
        ]);

        $teamId = (string) auth()->user()->current_team_id;

        try {
            $agent = $action->execute(
                teamId: $teamId,
                name: $validated['newName'],
                endpointUrl: $validated['newEndpointUrl'],
                description: $validated['newDescription'] ?: null,
            );

            session()->flash('success', "Registered remote agent: {$agent->name}");
            $this->showRegisterModal = false;
            $this->reset(['newName', 'newEndpointUrl', 'newDescription']);
            $this->redirect(route('external-agents.show', ['externalAgent' => $agent->id]));
        } catch (\Throwable $e) {
            $this->addError('newEndpointUrl', $e->getMessage());
        }
    }

    public function render(): View
    {
        $teamId = (string) auth()->user()->current_team_id;

        $agents = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($this->search !== '', fn ($q) => $q->where('name', 'ILIKE', "%{$this->search}%"))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('livewire.agent-chat.external-agent-list-page', [
            'agents' => $agents,
        ]);
    }
}
