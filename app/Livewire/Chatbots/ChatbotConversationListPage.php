<?php

namespace App\Livewire\Chatbots;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotSession;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ChatbotConversationListPage extends Component
{
    use WithPagination;

    public Chatbot $chatbot;

    #[Url]
    public string $channel = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public ?string $openSessionId = null;

    public function mount(Chatbot $chatbot): void
    {
        $this->chatbot = $chatbot;
    }

    public function openSession(string $sessionId): void
    {
        $this->openSessionId = $this->openSessionId === $sessionId ? null : $sessionId;
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        if (! ($team->settings['chatbot_enabled'] ?? false)) {
            return $this->redirect(route('dashboard'));
        }

        $query = ChatbotSession::where('chatbot_id', $this->chatbot->id)
            ->withCount('messages');

        if ($this->channel) {
            $query->where('channel', $this->channel);
        }

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $query->orderByDesc('created_at');

        $openSession = null;
        if ($this->openSessionId) {
            $openSession = ChatbotSession::where('id', $this->openSessionId)
                ->where('chatbot_id', $this->chatbot->id)
                ->with(['messages' => fn ($q) => $q->orderBy('created_at')])
                ->first();
        }

        return view('livewire.chatbots.chatbot-conversation-list-page', [
            'sessions' => $query->paginate(20),
            'openSession' => $openSession,
        ])->layout('layouts.app', ['header' => $this->chatbot->name.' — Conversations']);
    }
}
