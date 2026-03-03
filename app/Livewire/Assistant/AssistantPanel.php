<?php

namespace App\Livewire\Assistant;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Services\ConversationManager;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\GlobalSetting;
use Illuminate\Support\Str;
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

        if (! empty($this->conversations)) {
            $this->loadConversation($this->conversations[0]['id']);
        }
    }

    public function updatedSelectedProvider(): void
    {
        $providers = $this->resolveProvidersWithCustom();
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

            // Local agents (Claude Code, Codex) must go through execute() which runs the
            // tool loop and strips <tool_call> blocks from the output.
            // executeStreaming() is text-only and would expose raw <tool_call> tags in the UI.
            $isLocal = (bool) config("llm_providers.{$this->selectedProvider}.local");

            if ($isLocal) {
                $response = $action->execute(
                    conversation: $conversation,
                    userMessage: $message,
                    user: $user,
                    contextType: $this->contextType ?: null,
                    contextId: $this->contextId ?: null,
                    provider: $this->selectedProvider ?: null,
                    model: $this->selectedModel ?: null,
                    onChunk: function (string $cleanText): void {
                        $this->stream(
                            to: 'assistant-stream',
                            content: '<div class="assistant-response prose prose-sm max-w-none">'.Str::markdown($cleanText).'</div>',
                            replace: true,
                        );
                    },
                );
            } else {
                // Cloud providers: stream response token-by-token into the wire:stream target.
                $accumulated = '';

                $response = $action->executeStreaming(
                    conversation: $conversation,
                    userMessage: $message,
                    user: $user,
                    contextType: $this->contextType ?: null,
                    contextId: $this->contextId ?: null,
                    onChunk: function (string $chunk) use (&$accumulated): void {
                        $accumulated .= $chunk;
                        $this->stream(
                            to: 'assistant-stream',
                            content: '<div class="assistant-response prose prose-sm max-w-none">'.Str::markdown($accumulated).'</div>',
                            replace: true,
                        );
                    },
                    provider: $this->selectedProvider ?: null,
                    model: $this->selectedModel ?: null,
                );
            }

            // Clear the streaming bubble — the response will now appear in the
            // messages array and be rendered by the standard @foreach loop.
            $this->stream(to: 'assistant-stream', content: '', replace: true);

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
            $this->stream(to: 'assistant-stream', content: '', replace: true);
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
        return view('livewire.assistant.assistant-panel', [
            'providers' => $this->resolveProvidersWithCustom(),
        ]);
    }

    private function resolveProvidersWithCustom(): array
    {
        $resolver = app(ProviderResolver::class);
        $providers = $resolver->availableProviders();

        $team = auth()->user()?->currentTeam;
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
