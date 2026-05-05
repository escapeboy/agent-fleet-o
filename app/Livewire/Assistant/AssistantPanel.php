<?php

namespace App\Livewire\Assistant;

use App\Domain\Assistant\Jobs\ProcessAssistantMessageJob;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Services\ConversationManager;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Enums\TeamRole;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Mcp\Services\McpAppRegistry;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
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

    public array $compressionStats = [];

    public function mount(): void
    {
        $team = auth()->user()?->currentTeam;
        $teamSettings = $team?->settings ?? [];
        $savedProvider = $teamSettings['assistant_llm_provider']
            ?? GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');

        $availableProviders = $this->resolveProvidersWithCustom();

        // Fall back to the first available provider if the saved one is no longer available
        if (! isset($availableProviders[$savedProvider])) {
            $savedProvider = array_key_first($availableProviders) ?? $savedProvider;
        }

        $this->selectedProvider = $savedProvider;
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

    /**
     * Gap 2: scratchpad for artifact-form field values, keyed by messageId.
     * Populated by wire:model in the form artifact renderer, drained by
     * handleArtifactFormSubmit() when the user clicks submit.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $artifactForms = [];

    private const ARTIFACT_ACTION_RATE_LIMIT = 10;

    private const ARTIFACT_ACTION_RATE_WINDOW_SECONDS = 60;

    /**
     * Gap 2 hardening: entry gate for every artifact click. Enforces rate
     * limit, per-role gating, and writes an audit row for every attempt
     * (allowed or blocked). Returns true when the click is allowed to proceed.
     */
    private function authorizeArtifactClick(string $messageId, string $action, bool $destructive = false): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $team = $user->currentTeam;
        $role = $team ? $user->teamRole($team) : null;
        $roleValue = $role?->value ?? 'unknown';

        $rateKey = "assistant-artifact-click:{$user->id}";
        if (! RateLimiter::attempt(
            $rateKey,
            self::ARTIFACT_ACTION_RATE_LIMIT,
            fn () => null,
            self::ARTIFACT_ACTION_RATE_WINDOW_SECONDS,
        )) {
            $this->writeArtifactAudit($user->id, $team?->id, $messageId, $action, $roleValue, 'rate_limited', $destructive);
            session()->flash('assistant_error', 'Too many actions. Please wait a moment.');

            return false;
        }

        $allowed = match ($role) {
            TeamRole::Owner,
            TeamRole::Admin => true,
            TeamRole::Member => ! $destructive,
            TeamRole::Viewer => false,
            default => false,
        };

        if (! $allowed) {
            $this->writeArtifactAudit($user->id, $team?->id, $messageId, $action, $roleValue, 'role_blocked', $destructive);
            session()->flash('assistant_error', 'Your team role does not permit this action.');

            return false;
        }

        $this->writeArtifactAudit($user->id, $team?->id, $messageId, $action, $roleValue, 'allowed', $destructive);

        return true;
    }

    private function writeArtifactAudit(
        string $userId,
        ?string $teamId,
        string $messageId,
        string $action,
        string $role,
        string $outcome,
        bool $destructive,
    ): void {
        try {
            AuditEntry::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'event' => 'assistant.artifact_action',
                'subject_type' => AssistantMessage::class,
                'subject_id' => $messageId,
                'properties' => [
                    'action' => $action,
                    'role' => $role,
                    'outcome' => $outcome,
                    'destructive' => $destructive,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AssistantPanel: artifact audit write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleArtifactChoice(string $messageId, string $value, bool $destructive = false): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        if (! $this->authorizeArtifactClick($messageId, 'choice', $destructive)) {
            return;
        }

        $this->sendMessage("My choice: {$value}");
    }

    public function handleArtifactConfirm(string $messageId, bool $destructive = true): void
    {
        if (! $this->authorizeArtifactClick($messageId, 'confirm', $destructive)) {
            return;
        }

        $this->sendMessage('Confirmed. Please proceed.');
    }

    public function handleArtifactDismiss(string $messageId): void
    {
        if (! $this->authorizeArtifactClick($messageId, 'dismiss', false)) {
            return;
        }

        $this->sendMessage('Cancelled.');
    }

    public function handleArtifactFormSubmit(string $messageId): void
    {
        $data = $this->artifactForms[$messageId] ?? [];
        if (! is_array($data) || $data === []) {
            return;
        }

        if (! $this->authorizeArtifactClick($messageId, 'form_submit', false)) {
            return;
        }

        $summary = collect($data)
            ->map(fn ($value, $key) => sprintf('%s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value)))
            ->implode("\n");

        unset($this->artifactForms[$messageId]);

        $this->sendMessage("Form submission:\n{$summary}");
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
     * Handles three states: pending (thinking), streaming (partial content), completed/failed.
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

        $metadata = $msg->metadata ?? [];
        $status = $metadata['status'] ?? 'pending';

        if ($status === 'streaming') {
            // Show partial content as it arrives
            $lastIndex = array_key_last($this->messages);
            if ($lastIndex !== null) {
                $toolCalls = $metadata['tool_calls_in_progress'] ?? [];
                $this->messages[$lastIndex] = [
                    'role' => 'assistant',
                    'content' => $msg->content ?: null,
                    'pending' => true,
                    'streaming' => true,
                    'tool_calls_in_progress' => $toolCalls,
                ];
            }

            return;
        }

        if ($status === 'completed' || $status === 'failed') {
            // Job is done — replace the pending bubble with the real content
            $lastIndex = array_key_last($this->messages);
            if ($lastIndex !== null && ($this->messages[$lastIndex]['pending'] ?? false)) {
                $this->messages[$lastIndex] = [
                    'role' => 'assistant',
                    'content' => $msg->content ?? ('Sorry, an error occurred: '.($metadata['error'] ?? 'Unknown error')),
                    'tool_calls_count' => count($msg->tool_calls ?? []),
                    'cost_credits' => $msg->token_usage['cost_credits'] ?? 0,
                    'a2ui_surfaces' => $metadata['a2ui_surfaces'] ?? [],
                    'citations' => $metadata['citations'] ?? [],
                    'mcp_app_uris' => $this->extractMcpAppUris($msg->tool_calls),
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
                'a2ui_surfaces' => $m->metadata['a2ui_surfaces'] ?? [],
                'citations' => $m->metadata['citations'] ?? [],
                'mcp_app_uris' => $this->extractMcpAppUris($m->tool_calls),
            ])
            ->toArray();

        $this->compressionStats = $conversation->metadata['compression_stats'] ?? [];
        $this->showHistory = false;
        $this->dispatch('assistant-message-sent');
    }

    public function setContext(string $type, string $id): void
    {
        $this->contextType = $type;
        $this->contextId = $id;
    }

    /**
     * Listener for `assistant-set-selection` — any index page can dispatch
     *
     *     $this->dispatch('assistant-set-selection', kind: 'experiment', ids: [...]);
     *
     * to bundle a multi-select into the assistant's conversation context. The
     * chat auto-opens so the user can immediately type "pause all of these" or
     * "summarise them" and the model will call the right MCP tools per ID.
     *
     * @param  list<string>  $ids
     */
    #[On('assistant-set-selection')]
    public function applySelection(string $kind = '', array $ids = []): void
    {
        $kind = trim($kind);
        $ids = array_values(array_filter(array_map('strval', $ids), fn ($v) => $v !== ''));
        if ($kind === '' || $ids === []) {
            $this->contextType = '';
            $this->contextId = '';

            return;
        }

        $this->contextType = 'selection';
        $this->contextId = json_encode(['kind' => $kind, 'ids' => array_slice($ids, 0, 50)]);
        $this->dispatch('assistant-open');
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

    /**
     * Pre-fill the message textarea with the selected prompt and focus the input.
     */
    public function usePrompt(string $prompt): void
    {
        $this->dispatch('use-prompt', text: $prompt);
    }

    /**
     * Returns categorized prompt suggestions for the empty-state gallery.
     *
     * @return array<string, list<string>>
     */
    protected function getPromptGallery(): array
    {
        return [
            'Experiments' => [
                'Run a growth experiment to improve email open rates',
                'Score and rank the latest 50 signals by intent',
                'Retry the last failed experiment from the planning stage',
                'Show all experiments paused in the last 24 hours',
            ],
            'Agents & Skills' => [
                'Create an agent that summarizes news from RSS feeds daily',
                'Show which agents had the highest error rate this week',
                'List all active skills and their last execution time',
            ],
            'Monitoring' => [
                'What is my current budget spend and forecast?',
                'List approval requests expiring in the next 2 hours',
                'Show a summary of agent runs in the last 24 hours',
            ],
            'Crews & Workflows' => [
                'Execute the lead-scoring crew with the latest signal batch',
                'Show me the last crew execution results',
                'Build a workflow that scores then sends outbound',
            ],
            'Setup' => [
                'Add my Anthropic API key to this team',
                'Create a Slack outbound connector for sales alerts',
            ],
        ];
    }

    /**
     * Called by the AssistantPanel postMessage bridge when an MCP App iframe
     * invokes a tools/call request. Rate-limited to 30 calls/min per user.
     *
     * @param  array<string, mixed>  $params
     * @return array{content?: list<array{type: string, text: string}>, error?: string}
     */
    public function mcpAppCallTool(string $toolName, array $params = []): array
    {
        return McpAppRegistry::callTool($toolName, $params);
    }

    /**
     * @param  array<int, array{toolName: string}>|null  $toolCalls
     * @return array<string, string> Map of toolName => uri://
     */
    private function extractMcpAppUris(?array $toolCalls): array
    {
        if (! $toolCalls) {
            return [];
        }

        return McpAppRegistry::extractUris($toolCalls);
    }

    public function render()
    {
        return view('livewire.assistant.assistant-panel', [
            'providers' => $this->resolveProvidersWithCustom(),
            'promptGallery' => $this->getPromptGallery(),
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
