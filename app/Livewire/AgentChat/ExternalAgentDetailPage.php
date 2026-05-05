<?php

declare(strict_types=1);

namespace App\Livewire\AgentChat;

use App\Domain\AgentChatProtocol\Actions\DisableExternalAgentAction;
use App\Domain\AgentChatProtocol\Actions\DispatchChatMessageAction;
use App\Domain\AgentChatProtocol\Actions\RefreshExternalAgentManifestAction;
use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ExternalAgentDetailPage extends Component
{
    public ExternalAgent $externalAgent;

    public function mount(ExternalAgent $externalAgent): void
    {
        abort_unless(
            (string) $externalAgent->team_id === (string) auth()->user()->current_team_id,
            404,
        );
        $this->externalAgent = $externalAgent;
    }

    public function refreshManifest(RefreshExternalAgentManifestAction $action): void
    {
        try {
            $this->externalAgent = $action->execute($this->externalAgent);
            session()->flash('success', 'Manifest refreshed');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function ping(DispatchChatMessageAction $action): void
    {
        try {
            $action->execute(
                externalAgent: $this->externalAgent,
                content: 'ping',
                from: 'fleetq:team:'.$this->externalAgent->team_id.':ui-ping',
            );
            session()->flash('success', 'Ping delivered — remote responded.');
        } catch (\Throwable $e) {
            session()->flash('error', 'Ping failed: '.$e->getMessage());
        }
        $this->externalAgent->refresh();
    }

    public function disable(DisableExternalAgentAction $action): void
    {
        $action->execute($this->externalAgent, softDelete: true);
        session()->flash('success', 'Agent disabled and archived.');
        $this->redirect(route('external-agents.index'));
    }

    public function render(): View
    {
        $recentMessages = AgentChatMessage::withoutGlobalScopes()
            ->where('team_id', $this->externalAgent->team_id)
            ->where('external_agent_id', $this->externalAgent->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('livewire.agent-chat.external-agent-detail-page', [
            'recentMessages' => $recentMessages,
        ]);
    }
}
