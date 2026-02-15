<?php

namespace App\Livewire\Assistant;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\Assistant\Services\ConversationManager;
use Livewire\Component;

class AssistantPanel extends Component
{
    public string $userMessage = '';

    public string $conversationId = '';

    public array $messages = [];

    public bool $isProcessing = false;

    public string $contextType = '';

    public string $contextId = '';

    public string $streamedResponse = '';

    public array $conversations = [];

    public bool $showHistory = false;

    public function mount(): void
    {
        $this->loadRecentConversations();
    }

    public function sendMessage(): void
    {
        $message = trim($this->userMessage);
        if ($message === '' || $this->isProcessing) {
            return;
        }

        $this->userMessage = '';
        $this->isProcessing = true;

        // Add user message to UI immediately
        $this->messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

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

            // Use tool-calling (synchronous) path - handles both tools and text
            $response = $action->execute(
                conversation: $conversation,
                userMessage: $message,
                user: $user,
                contextType: $this->contextType ?: null,
                contextId: $this->contextId ?: null,
            );

            // Add assistant response to UI
            $this->messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls_count' => $response->toolCallsCount,
                'cost_credits' => $response->usage->costCredits,
            ];

            $this->loadRecentConversations();
        } catch (\Throwable $e) {
            $this->messages[] = [
                'role' => 'assistant',
                'content' => 'Sorry, an error occurred: '.$e->getMessage(),
            ];
        }

        $this->isProcessing = false;
        $this->dispatch('assistant-message-sent');
    }

    public function newConversation(): void
    {
        $this->conversationId = '';
        $this->messages = [];
        $this->streamedResponse = '';
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
        return view('livewire.assistant.assistant-panel');
    }
}
