<?php

namespace App\Domain\Assistant\Jobs;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
            // Progressive streaming: update placeholder as chunks arrive.
            // Throttle DB writes to max once per 300ms to avoid excessive queries.
            $streamedContent = '';
            $toolCalls = [];
            $lastFlush = 0.0;
            $flushInterval = 0.3; // seconds

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

            // Detect empty bridge response: agent ran but produced no output.
            // This happens when the agent CLI exits non-zero or produces no text,
            // but the bridge does not propagate an explicit error frame.
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
