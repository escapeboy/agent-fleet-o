<?php

namespace App\Livewire\Chatbots;

use App\Domain\Chatbot\Enums\ChatbotStatus;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ChatbotListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $typeFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        // Guard: feature must be enabled
        if (! ($team->settings['chatbot_enabled'] ?? false)) {
            return $this->redirect(route('dashboard'));
        }

        $query = Chatbot::query()->withCount('activeChannels');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('slug', 'ilike', "%{$this->search}%")
                    ->orWhere('description', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        $query->orderByDesc('created_at');

        return view('livewire.chatbots.chatbot-list-page', [
            'chatbots' => $query->paginate(20),
            'statuses' => ChatbotStatus::cases(),
            'types' => ChatbotType::cases(),
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Chatbots']);
    }
}
