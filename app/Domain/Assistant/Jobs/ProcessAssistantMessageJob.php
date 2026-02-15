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
use Illuminate\Support\Facades\Log;

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

        try {
            $action->execute(
                conversation: $conversation,
                userMessage: $this->userMessage,
                user: $user,
                contextType: $this->contextType,
                contextId: $this->contextId,
                provider: $this->provider,
                model: $this->model,
                placeholderMessageId: $this->placeholderMessageId,
            );
        } catch (\Throwable $e) {
            Log::error('ProcessAssistantMessageJob failed', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);

            $placeholder->update([
                'content' => 'Sorry, an error occurred: '.$e->getMessage(),
                'metadata' => ['status' => 'failed', 'error' => $e->getMessage()],
            ]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $placeholder = AssistantMessage::find($this->placeholderMessageId);

        $placeholder?->update([
            'content' => 'Sorry, the request failed unexpectedly. Please try again.',
            'metadata' => ['status' => 'failed', 'error' => $e?->getMessage() ?? 'Unknown error'],
        ]);

        Log::error('ProcessAssistantMessageJob hard failure', [
            'conversation_id' => $this->conversationId,
            'error' => $e?->getMessage(),
        ]);
    }
}
