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

            // Always use execute() so tool calling works for all providers.
            // - Local agents (Claude Code): text-based <tool_call> loop, onChunk fires per token.
            // - Cloud providers: PrismPHP tool calling, onChunk fires once with full content
            //   (gateway falls back to complete() when tools are present, so no mid-stream calls).
            $accumulated = '';

            $response = $action->execute(
                conversation: $conversation,
                userMessage: $message,
                user: $user,
                contextType: $this->contextType ?: null,
                contextId: $this->contextId ?: null,
                provider: $this->selectedProvider ?: null,
                model: $this->selectedModel ?: null,
                onChunk: function (string $chunk) use (&$accumulated): void {
                    $accumulated .= $chunk;
                    $this->stream(
                        to: 'assistant-stream',
                        content: '<div class="assistant-response prose prose-sm max-w-none">'.Str::markdown($accumulated).'</div>',
                        replace: true,
                    );
                },
            );

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
