<?php

namespace App\Livewire\Assistant;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\Assistant\Services\ConversationManager;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\GlobalSetting;
use Livewire\Component;

class AssistantPanel extends Component
{
    public string $conversationId = '';

    public array $messages = [];

    public string $contextType = '';

    public string $contextId = '';

    public array $conversations = [];

    public bool $showHistory = false;

    public string $selectedProvider = '';

    public string $selectedModel = '';

    public function mount(): void
    {
        $this->selectedProvider = GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $this->selectedModel = GlobalSetting::get('assistant_llm_model')
            ?? GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5');

        $this->loadRecentConversations();
    }

    public function updatedSelectedProvider(): void
    {
        $providers = app(ProviderResolver::class)->availableProviders();
        $models = $providers[$this->selectedProvider]['models'] ?? [];
        $this->selectedModel = array_key_first($models) ?? '';

        GlobalSetting::set('assistant_llm_provider', $this->selectedProvider);
        GlobalSetting::set('assistant_llm_model', $this->selectedModel);
    }

    public function updatedSelectedModel(): void
    {
        GlobalSetting::set('assistant_llm_model', $this->selectedModel);
    }

    public function sendMessage(string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        try {
            $user = auth()->user();
            $manager = app(ConversationManager::class);

            // Get or create conversation
            $conversation = $manager->getOrCreateConversation(
                userId: $user->id,
                teamId: $user->current_team_id,
                conversationId: $this->conversationId ?: null,
                contextType: $this->contextType ?: null,
                contextId: $this->contextId ?: null,
            );
            $this->conversationId = $conversation->id;

            $action = app(SendAssistantMessageAction::class);

            $response = $action->execute(
                conversation: $conversation,
                userMessage: $message,
                user: $user,
                contextType: $this->contextType ?: null,
                contextId: $this->contextId ?: null,
                provider: $this->selectedProvider ?: null,
                model: $this->selectedModel ?: null,
            );

            // Add both user message and assistant response to the messages array.
            // Alpine shows the user message optimistically during the request,
            // and clears it when the server response arrives with both messages.
            $this->messages[] = [
                'role' => 'user',
                'content' => $message,
            ];
            $this->messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls_count' => $response->toolCallsCount,
                'cost_credits' => $response->usage->costCredits,
            ];

            $this->loadRecentConversations();
        } catch (\Throwable $e) {
            $this->messages[] = [
                'role' => 'user',
                'content' => $message,
            ];
            $this->messages[] = [
                'role' => 'assistant',
                'content' => 'Sorry, an error occurred: '.$e->getMessage(),
            ];
        }
    }

    public function newConversation(): void
    {
        $this->conversationId = '';
        $this->messages = [];
        $this->showHistory = false;
    }

    public function loadConversation(string $id): void
    {
        $user = auth()->user();
        $manager = app(ConversationManager::class);

        $conversation = $manager->getOrCreateConversation(
            userId: $user->id,
            teamId: $user->current_team_id,
            conversationId: $id,
        );

        $this->conversationId = $conversation->id;
        $this->contextType = $conversation->context_type ?? '';
        $this->contextId = $conversation->context_id ?? '';

        // Load messages
        $this->messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'tool_calls_count' => $m->tool_calls ? count($m->tool_calls) : 0,
                'cost_credits' => $m->token_usage['cost_credits'] ?? 0,
            ])
            ->toArray();

        $this->showHistory = false;
        $this->dispatch('assistant-message-sent');
    }

    public function setContext(string $type, string $id): void
    {
        $this->contextType = $type;
        $this->contextId = $id;
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
        if ($this->showHistory) {
            $this->loadRecentConversations();
        }
    }

    private function loadRecentConversations(): void
    {
        $manager = app(ConversationManager::class);
        $this->conversations = $manager->getRecentConversations(auth()->id())
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title ?? 'New conversation',
                'last_message_at' => $c->last_message_at?->diffForHumans() ?? '',
            ])
            ->toArray();
    }

    public function render()
    {
        $providers = app(ProviderResolver::class)->availableProviders();

        return view('livewire.assistant.assistant-panel', [
            'providers' => $providers,
        ]);
    }
}
