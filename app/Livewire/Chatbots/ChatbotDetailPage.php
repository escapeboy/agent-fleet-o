<?php

namespace App\Livewire\Chatbots;

use App\Domain\Chatbot\Actions\CreateChatbotTokenAction;
use App\Domain\Chatbot\Actions\DeleteChatbotAction;
use App\Domain\Chatbot\Actions\RevokeChatbotTokenAction;
use App\Domain\Chatbot\Actions\ToggleChatbotStatusAction;
use App\Domain\Chatbot\Actions\UpdateChatbotAction;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotToken;
use Livewire\Component;

class ChatbotDetailPage extends Component
{
    public Chatbot $chatbot;

    public string $activeTab = 'overview';

    // Edit state
    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    public string $editWelcomeMessage = '';

    public string $editFallbackMessage = '';

    public float $editConfidenceThreshold = 0.70;

    public bool $editHumanEscalationEnabled = false;

    // Token generation
    public bool $showNewTokenModal = false;

    public string $newTokenName = 'Default';

    public string $generatedToken = '';

    public function mount(Chatbot $chatbot): void
    {
        $this->chatbot = $chatbot;
    }

    public function toggleStatus(): void
    {
        app(ToggleChatbotStatusAction::class)->execute($this->chatbot);
        $this->chatbot->refresh();
        session()->flash('message', 'Chatbot status updated.');
    }

    public function startEdit(): void
    {
        $this->editName = $this->chatbot->name;
        $this->editDescription = $this->chatbot->description ?? '';
        $this->editWelcomeMessage = $this->chatbot->welcome_message ?? '';
        $this->editFallbackMessage = $this->chatbot->fallback_message ?? '';
        $this->editConfidenceThreshold = (float) $this->chatbot->confidence_threshold;
        $this->editHumanEscalationEnabled = $this->chatbot->human_escalation_enabled;
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editConfidenceThreshold' => 'required|numeric|min:0.1|max:1.0',
            'editWelcomeMessage' => 'nullable|max:500',
            'editFallbackMessage' => 'nullable|max:500',
        ]);

        app(UpdateChatbotAction::class)->execute(
            chatbot: $this->chatbot,
            name: $this->editName,
            description: $this->editDescription ?: null,
            welcomeMessage: $this->editWelcomeMessage ?: null,
            fallbackMessage: $this->editFallbackMessage ?: null,
            confidenceThreshold: $this->editConfidenceThreshold,
            humanEscalationEnabled: $this->editHumanEscalationEnabled,
        );

        $this->chatbot->refresh();
        $this->editing = false;
        session()->flash('message', 'Chatbot updated successfully.');
    }

    public function generateToken(): void
    {
        $this->validate([
            'newTokenName' => 'required|min:1|max:100',
        ]);

        $result = app(CreateChatbotTokenAction::class)->execute(
            chatbot: $this->chatbot,
            name: $this->newTokenName,
        );

        $this->generatedToken = $result['token'] ?? '';
        $this->showNewTokenModal = false;

        session()->flash('generated_token', $this->generatedToken);
        session()->flash('message', 'New API token generated. Save it now — it will not be shown again.');

        $this->chatbot->refresh();
    }

    public function revokeToken(string $tokenId): void
    {
        $token = ChatbotToken::where('id', $tokenId)
            ->where('chatbot_id', $this->chatbot->id)
            ->firstOrFail();

        app(RevokeChatbotTokenAction::class)->execute($token);

        $this->chatbot->refresh();
        session()->flash('message', 'Token revoked.');
    }

    public function delete(): void
    {
        app(DeleteChatbotAction::class)->execute($this->chatbot);

        session()->flash('message', 'Chatbot deleted.');
        $this->redirect(route('chatbots.index'));
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        if (! ($team->settings['chatbot_enabled'] ?? false)) {
            return $this->redirect(route('dashboard'));
        }

        return view('livewire.chatbots.chatbot-detail-page', [
            'tokens' => $this->chatbot->tokens()->orderByDesc('created_at')->get(),
            'channels' => $this->chatbot->channels()->get(),
            'sessionsCount' => $this->chatbot->sessions()->count(),
            'messagesCount' => $this->chatbot->messages()->count(),
        ])->layout('layouts.app', ['header' => $this->chatbot->name]);
    }
}
