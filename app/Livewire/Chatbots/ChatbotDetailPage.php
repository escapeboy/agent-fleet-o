<?php

namespace App\Livewire\Chatbots;

use App\Domain\Chatbot\Actions\CreateChatbotTokenAction;
use App\Domain\Chatbot\Actions\DeleteChatbotAction;
use App\Domain\Chatbot\Actions\RevokeChatbotTokenAction;
use App\Domain\Chatbot\Actions\ToggleChatbotStatusAction;
use App\Domain\Chatbot\Actions\UpdateChatbotAction;
use App\Domain\Chatbot\Enums\ChannelType;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotToken;
use App\Domain\Workflow\Models\Workflow;
use App\Infrastructure\AI\Services\ProviderResolver;
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

    public string $editProvider = 'anthropic';

    public string $editModel = 'claude-sonnet-4-5';

    public string $editSystemPrompt = '';

    public string $editWorkflowId = '';

    // Token generation
    public bool $showNewTokenModal = false;

    public string $newTokenName = 'Default';

    public string $generatedToken = '';

    // Telegram channel
    public bool $showTelegramForm = false;

    public string $telegramBotToken = '';

    public string $telegramWebhookSecret = '';

    public ?string $editingChannelId = null;

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
        $this->editProvider = $this->chatbot->agent?->provider ?? 'anthropic';
        $this->editModel = $this->chatbot->agent?->model ?? 'claude-sonnet-4-5';
        $this->editSystemPrompt = $this->chatbot->agent?->backstory ?? '';
        $this->editWorkflowId = $this->chatbot->workflow_id ?? '';
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
            'editProvider' => 'required|string',
            'editModel' => 'required|string',
        ]);

        app(UpdateChatbotAction::class)->execute(
            chatbot: $this->chatbot,
            name: $this->editName,
            description: $this->editDescription ?: null,
            welcomeMessage: $this->editWelcomeMessage ?: null,
            fallbackMessage: $this->editFallbackMessage ?: null,
            confidenceThreshold: $this->editConfidenceThreshold,
            humanEscalationEnabled: $this->editHumanEscalationEnabled,
            workflowId: $this->editWorkflowId ?: null,
            provider: $this->chatbot->agent_is_dedicated ? $this->editProvider : null,
            model: $this->chatbot->agent_is_dedicated ? $this->editModel : null,
            systemPrompt: $this->chatbot->agent_is_dedicated && $this->editSystemPrompt !== '' ? $this->editSystemPrompt : null,
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

    public function startTelegramEdit(?string $channelId = null): void
    {
        if ($channelId) {
            $channel = ChatbotChannel::where('id', $channelId)
                ->where('chatbot_id', $this->chatbot->id)
                ->firstOrFail();
            $this->telegramBotToken = $channel->config['bot_token'] ?? '';
            $this->telegramWebhookSecret = $channel->config['webhook_secret'] ?? '';
            $this->editingChannelId = $channelId;
        } else {
            $this->telegramBotToken = '';
            $this->telegramWebhookSecret = '';
            $this->editingChannelId = null;
        }
        $this->showTelegramForm = true;
    }

    public function cancelTelegramForm(): void
    {
        $this->showTelegramForm = false;
        $this->telegramBotToken = '';
        $this->telegramWebhookSecret = '';
        $this->editingChannelId = null;
    }

    public function saveTelegramChannel(): void
    {
        $this->validate([
            'telegramBotToken' => 'required|string|min:10',
            'telegramWebhookSecret' => 'nullable|string|max:256',
        ]);

        $config = ['bot_token' => trim($this->telegramBotToken)];
        if ($this->telegramWebhookSecret !== '') {
            $config['webhook_secret'] = $this->telegramWebhookSecret;
        }

        if ($this->editingChannelId) {
            ChatbotChannel::where('id', $this->editingChannelId)
                ->where('chatbot_id', $this->chatbot->id)
                ->update(['config' => $config]);
        } else {
            ChatbotChannel::create([
                'chatbot_id' => $this->chatbot->id,
                'channel_type' => ChannelType::Telegram,
                'config' => $config,
                'is_active' => true,
            ]);
        }

        $this->chatbot->refresh();
        $this->cancelTelegramForm();
        session()->flash('message', 'Telegram channel saved.');
    }

    public function toggleTelegramChannel(string $channelId): void
    {
        $channel = ChatbotChannel::where('id', $channelId)
            ->where('chatbot_id', $this->chatbot->id)
            ->firstOrFail();
        $channel->update(['is_active' => ! $channel->is_active]);
        $this->chatbot->refresh();
    }

    public function deleteTelegramChannel(string $channelId): void
    {
        ChatbotChannel::where('id', $channelId)
            ->where('chatbot_id', $this->chatbot->id)
            ->delete();
        $this->chatbot->refresh();
        session()->flash('message', 'Telegram channel removed.');
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
            'providers' => app(ProviderResolver::class)->availableProviders(),
            'workflows' => Workflow::where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['header' => $this->chatbot->name]);
    }
}
