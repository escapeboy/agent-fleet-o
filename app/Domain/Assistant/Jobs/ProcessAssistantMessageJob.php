<?php

namespace App\Domain\Assistant\Jobs;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Agents\FleetQAssistant;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Services\ConversationManager;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Sentry\Severity;
use Sentry\State\Scope;

class ProcessAssistantMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $placeholderMessageId,
        public readonly string $userMessage,
        public readonly string $userId,
        public readonly string $teamId,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $contextType = null,
        public readonly ?string $contextId = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(SendAssistantMessageAction $action): void
    {
        $conversation = AssistantConversation::withoutGlobalScopes()->find($this->conversationId);
        $user = User::find($this->userId);
        $placeholder = AssistantMessage::find($this->placeholderMessageId);

        if (! $conversation || ! $user || ! $placeholder) {
            Log::warning('ProcessAssistantMessageJob: missing conversation, user, or placeholder', [
                'conversation_id' => $this->conversationId,
                'user_id' => $this->userId,
                'placeholder_id' => $this->placeholderMessageId,
            ]);

            return;
        }

        // Ensure user has team context loaded
        if (! $user->current_team_id) {
            $user->current_team_id = $this->teamId;
        }
        $user->load('currentTeam');

        // Login user into Auth guard so tool closures can resolve via auth()->user()
        // IMPORTANT: Must forgetUser in finally{} — Horizon workers are long-lived,
        // auth state would leak to the next job on the same worker.
        Auth::login($user);

        try {
            // Route: cloud providers use laravel/ai Agent (native streaming + tool events),
            // local/bridge providers use legacy SendAssistantMessageAction path.
            $isLocal = $this->provider === 'local' || (bool) config("llm_providers.{$this->provider}.local");
            $isBridge = $this->provider === 'bridge_agent';

            if (! $isLocal && ! $isBridge) {
                $this->handleWithLaravelAiAgent($conversation, $user, $placeholder);
            } else {
                $this->handleWithLegacyAction($action, $conversation, $user, $placeholder);
            }

            // Detect empty bridge response
            $placeholder->refresh();
            if (($placeholder->content ?? '') === '' && str_contains($this->provider ?? '', 'bridge')) {
                \Sentry\withScope(function (Scope $scope): void {
                    $scope->setTag('provider', $this->provider ?? 'unknown');
                    $scope->setTag('model', $this->model ?? 'unknown');
                    $scope->setContext('bridge_debug', [
                        'provider' => $this->provider,
                        'model' => $this->model,
                        'team_id' => $this->teamId,
                        'conversation_id' => $this->conversationId,
                        'placeholder_id' => $this->placeholderMessageId,
                    ]);
                    \Sentry\captureMessage(
                        'Bridge agent returned empty response: '.($this->provider ?? '?').'/'.($this->model ?? '?'),
                        Severity::warning(),
                    );
                });

                Log::warning('ProcessAssistantMessageJob: bridge agent returned empty response', [
                    'conversation_id' => $this->conversationId,
                    'provider' => $this->provider,
                    'model' => $this->model,
                    'team_id' => $this->teamId,
                ]);

                $placeholder->update([
                    'content' => sprintf(
                        'No response from agent. The `%s` agent ran but returned no output. Check that the agent is authenticated and the model name is valid.',
                        $this->model ?? 'unknown',
                    ),
                    'metadata' => ['status' => 'failed', 'error' => 'empty_bridge_response'],
                ]);
            }
        } catch (\Throwable $e) {
            \Sentry\withScope(function (Scope $scope) use ($e): void {
                $scope->setTag('provider', $this->provider ?? 'unknown');
                $scope->setTag('model', $this->model ?? 'unknown');
                $scope->setContext('bridge_debug', [
                    'provider' => $this->provider,
                    'model' => $this->model,
                    'team_id' => $this->teamId,
                    'conversation_id' => $this->conversationId,
                    'placeholder_id' => $this->placeholderMessageId,
                ]);
                \Sentry\captureException($e);
            });

            Log::error('ProcessAssistantMessageJob failed', [
                'conversation_id' => $this->conversationId,
                'provider' => $this->provider,
                'model' => $this->model,
                'team_id' => $this->teamId,
                'error' => $e->getMessage(),
            ]);

            $placeholder->update([
                'content' => 'Sorry, an error occurred: '.$e->getMessage(),
                'metadata' => ['status' => 'failed', 'error' => $e->getMessage()],
            ]);
        } finally {
            // Clean up auth state to prevent leaking to next job on same Horizon worker
            Auth::forgetUser();
            Auth::guard()->forgetUser();
        }
    }

    /**
     * Handle with laravel/ai Agent — native streaming + tool events.
     */
    private function handleWithLaravelAiAgent(
        AssistantConversation $conversation,
        User $user,
        AssistantMessage $placeholder,
    ): void {
        $manager = app(ConversationManager::class);

        // Resolve provider/model from team settings
        $teamSettings = $user->currentTeam?->settings ?? [];
        $provider = $this->provider
            ?? ($teamSettings['assistant_llm_provider'] ?? null)
            ?? config('ai.default_provider', 'anthropic');
        $model = $this->model
            ?? ($teamSettings['assistant_llm_model'] ?? null)
            ?? config('ai.default_model');

        $agent = (new FleetQAssistant)
            ->forUser($user)
            ->withContext($this->contextType, $this->contextId);

        // Build conversation history for context
        $history = $manager->buildMessageHistory($conversation);
        $prompt = ! empty($history)
            ? "Previous conversation:\n".implode("\n", array_map(fn ($m) => "[{$m['role']}] {$m['content']}", $history))."\n\nUser: {$this->userMessage}"
            : $this->userMessage;

        // Stream the response — iterate events for real-time progress
        $streamResponse = $agent->stream(
            prompt: $prompt,
            provider: $provider,
            model: $model,
        );

        $streamedContent = '';
        $toolCallNames = [];
        $lastFlush = 0.0;
        $flushInterval = 0.3;
        $toolCallsCount = 0;

        foreach ($streamResponse as $event) {
            if ($event instanceof TextDelta) {
                $streamedContent .= $event->delta;

                $now = microtime(true);
                if (($now - $lastFlush) >= $flushInterval) {
                    $placeholder->update([
                        'content' => $streamedContent,
                        'metadata' => json_encode([
                            'status' => 'streaming',
                            'tool_calls_in_progress' => $toolCallNames,
                        ]),
                    ]);
                    $lastFlush = $now;
                }
            } elseif ($event instanceof ToolCall) {
                $toolCallNames[] = $event->toolCall->name;
                $toolCallsCount++;
                $placeholder->update([
                    'content' => $streamedContent,
                    'metadata' => json_encode([
                        'status' => 'streaming',
                        'tool_calls_in_progress' => $toolCallNames,
                    ]),
                ]);
                $lastFlush = microtime(true);
            }
        }

        // Final content from stream
        $finalContent = $streamResponse->text ?? $streamedContent;

        // Save completed message
        $placeholder->update([
            'content' => $finalContent,
            'tool_calls' => $toolCallsCount > 0 ? json_encode(array_map(fn ($n) => ['toolName' => $n], $toolCallNames)) : null,
            'token_usage' => $streamResponse->usage ? json_encode([
                'prompt_tokens' => $streamResponse->usage->inputTokens ?? 0,
                'completion_tokens' => $streamResponse->usage->outputTokens ?? 0,
                'cost_credits' => 0, // TODO: calculate from usage
            ]) : null,
            'metadata' => json_encode(['status' => 'completed']),
        ]);

        // Save to conversation manager
        $manager->addMessage(
            conversation: $conversation,
            role: 'assistant',
            content: $finalContent,
        );
        $manager->generateTitle($conversation);
    }

    /**
     * Handle with legacy SendAssistantMessageAction — for local/bridge providers.
     */
    private function handleWithLegacyAction(
        SendAssistantMessageAction $action,
        AssistantConversation $conversation,
        User $user,
        AssistantMessage $placeholder,
    ): void {
        $streamedContent = '';
        $toolCalls = [];
        $lastFlush = 0.0;
        $flushInterval = 0.3;

        $onChunk = function (?string $textDelta, string $type = 'text_delta', ?string $toolName = null) use ($placeholder, &$streamedContent, &$toolCalls, &$lastFlush, $flushInterval): void {
            if ($type === 'tool_call' && $toolName) {
                $toolCalls[] = $toolName;
                $placeholder->update([
                    'content' => $streamedContent,
                    'metadata' => json_encode([
                        'status' => 'streaming',
                        'tool_calls_in_progress' => $toolCalls,
                    ]),
                ]);
                $lastFlush = microtime(true);

                return;
            }

            if ($textDelta !== null) {
                $streamedContent .= $textDelta;
            }

            $now = microtime(true);
            if (($now - $lastFlush) >= $flushInterval) {
                $placeholder->update([
                    'content' => $streamedContent,
                    'metadata' => json_encode([
                        'status' => 'streaming',
                        'tool_calls_in_progress' => $toolCalls,
                    ]),
                ]);
                $lastFlush = $now;
            }
        };

        $action->execute(
            conversation: $conversation,
            userMessage: $this->userMessage,
            user: $user,
            contextType: $this->contextType,
            contextId: $this->contextId,
            provider: $this->provider,
            model: $this->model,
            onChunk: $onChunk,
            placeholderMessageId: $this->placeholderMessageId,
        );
    }

    public function failed(?\Throwable $e): void
    {
        \Sentry\withScope(function (Scope $scope) use ($e): void {
            $scope->setTag('provider', $this->provider ?? 'unknown');
            $scope->setTag('model', $this->model ?? 'unknown');
            $scope->setContext('bridge_debug', [
                'provider' => $this->provider,
                'model' => $this->model,
                'team_id' => $this->teamId,
                'conversation_id' => $this->conversationId,
                'placeholder_id' => $this->placeholderMessageId,
            ]);
            if ($e) {
                \Sentry\captureException($e);
            }
        });

        $placeholder = AssistantMessage::find($this->placeholderMessageId);

        $placeholder?->update([
            'content' => 'Sorry, the request failed unexpectedly. Please try again.',
            'metadata' => ['status' => 'failed', 'error' => $e?->getMessage() ?? 'Unknown error'],
        ]);

        Log::error('ProcessAssistantMessageJob hard failure', [
            'conversation_id' => $this->conversationId,
            'provider' => $this->provider,
            'model' => $this->model,
            'team_id' => $this->teamId,
            'error' => $e?->getMessage(),
        ]);
    }
}
