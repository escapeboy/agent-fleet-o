<?php

namespace App\Livewire\Assistant;

use App\Domain\Assistant\Jobs\ProcessAssistantMessageJob;
use App\Domain\Assistant\Models\AssistantMessage;
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

    /** ID of the pending placeholder assistant message (empty when idle). */
    public string $pendingMessageId = '';

    public function mount(): void
    {
        $team = auth()->user()?->currentTeam;
        $teamSettings = $team?->settings ?? [];
        $this->selectedProvider = $teamSettings['assistant_llm_provider']
            ?? GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $this->selectedModel = $teamSettings['assistant_llm_model']
            ?? GlobalSetting::get('assistant_llm_model')
            ?? GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5');

        $this->loadRecentConversations();

        if (! empty($this->conversations)) {
            $this->loadConversation($this->conversations[0]['id']);
        }
    }

    public function updatedSelectedProvider(): void
    {
        $providers = $this->resolveProvidersWithCustom();
        $models = $providers[$this->selectedProvider]['models'] ?? [];
        $this->selectedModel = array_key_first($models) ?? '';

        $this->saveAssistantLlmToTeam($this->selectedProvider, $this->selectedModel);
    }

    public function updatedSelectedModel(): void
    {
        $this->saveAssistantLlmToTeam($this->selectedProvider, $this->selectedModel);
    }

    private function saveAssistantLlmToTeam(string $provider, string $model): void
    {
        $team = auth()->user()?->currentTeam;
        if ($team) {
            $settings = $team->settings ?? [];
            $settings['assistant_llm_provider'] = $provider;
            $settings['assistant_llm_model'] = $model;
            $team->update(['settings' => $settings]);
        } else {
            GlobalSetting::set('assistant_llm_provider', $provider);
            GlobalSetting::set('assistant_llm_model', $model);
        }
    }

    public function sendMessage(string $message): void
    {
        $message = trim($message);
        if ($message === '' || $this->pendingMessageId !== '') {
            return; // Ignore while a response is pending
        }

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

        // Save user message immediately so it persists before the job runs
        $manager->addMessage($conversation, 'user', $message);

        // Create a placeholder assistant message — the job will fill it in.
        // content must be non-null (DB constraint); use '' as sentinel value.
        $placeholder = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'metadata' => ['status' => 'pending'],
            'created_at' => now(),
        ]);

        $this->pendingMessageId = $placeholder->id;

        // Optimistic UI — show user message + "thinking" bubble immediately
        $this->messages[] = ['role' => 'user', 'content' => $message];
        $this->messages[] = ['role' => 'assistant', 'content' => null, 'pending' => true];

        // Dispatch to ai-calls queue (timeout 900s — no HTTP timeout risk)
        ProcessAssistantMessageJob::dispatch(
            conversationId: $conversation->id,
            placeholderMessageId: $placeholder->id,
            userMessage: $message,
            userId: $user->id,
            teamId: $user->current_team_id,
            provider: $this->selectedProvider ?: null,
            model: $this->selectedModel ?: null,
            contextType: $this->contextType ?: null,
            contextId: $this->contextId ?: null,
        );

        $this->loadRecentConversations();
    }

    /**
     * Called by wire:poll — checks if the pending placeholder has been filled.
     */
    public function pollPendingMessage(): void
    {
        if ($this->pendingMessageId === '') {
            return;
        }

        $msg = AssistantMessage::find($this->pendingMessageId);

        if (! $msg) {
            $this->pendingMessageId = '';

            return;
        }

        $status = $msg->metadata['status'] ?? 'pending';
        if ($status === 'completed' || $status === 'failed') {
            // Job is done — replace the pending bubble with the real content
            $lastIndex = array_key_last($this->messages);
            if ($lastIndex !== null && ($this->messages[$lastIndex]['pending'] ?? false)) {
                $this->messages[$lastIndex] = [
                    'role' => 'assistant',
                    'content' => $msg->content ?? ('Sorry, an error occurred: '.($msg->metadata['error'] ?? 'Unknown error')),
                    'tool_calls_count' => count($msg->tool_calls ?? []),
                    'cost_credits' => $msg->token_usage['cost_credits'] ?? 0,
                ];
            }

            $this->pendingMessageId = '';
            $this->loadRecentConversations();
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
        return view('livewire.assistant.assistant-panel', [
            'providers' => $this->resolveProvidersWithCustom(),
        ]);
    }

    private function resolveProvidersWithCustom(): array
    {
        $resolver = app(ProviderResolver::class);
        $team = auth()->user()?->currentTeam;
        $providers = $resolver->availableProviders($team);
        foreach ($resolver->customEndpointsForTeam($team) as $ep) {
            $models = [];
            foreach ($ep->credentials['models'] ?? [] as $m) {
                $models[$m] = ['label' => $m, 'input_cost' => 0, 'output_cost' => 0];
            }
            $providers["custom_endpoint:{$ep->name}"] = [
                'name' => $ep->name.' (Custom)',
                'models' => $models,
            ];
        }

        return $providers;
    }
}
