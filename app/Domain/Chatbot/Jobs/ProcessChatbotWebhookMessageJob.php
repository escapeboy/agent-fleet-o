<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessChatbotWebhookMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $chatbotId,
        public readonly string $channelId,
        public readonly string $externalUserId,
        public readonly string $text,
        public readonly array $payload = [],
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(ChatbotResponseService $responseService): void
    {
        $chatbot = Chatbot::find($this->chatbotId);

        if (! $chatbot || ! $chatbot->status->isActive() || ! $chatbot->hasBudgetRemaining()) {
            return;
        }

        $channel = ChatbotChannel::withoutGlobalScopes()->find($this->channelId);

        if (! $channel || ! $channel->is_active) {
            return;
        }

        // Find or create session per external user ID
        $session = ChatbotSession::withoutGlobalScopes()
            ->where('chatbot_id', $chatbot->id)
            ->where('channel', 'webhook')
            ->where('external_user_id', $this->externalUserId)
            ->where('last_activity_at', '>=', now()->subHours(24))
            ->first();

        if (! $session) {
            $session = ChatbotSession::create([
                'chatbot_id' => $chatbot->id,
                'team_id' => $chatbot->team_id,
                'channel' => 'webhook',
                'external_user_id' => $this->externalUserId,
                'metadata' => ['source' => 'webhook', 'payload_preview' => array_slice($this->payload, 0, 5)],
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        }

        try {
            $responseService->handle(
                chatbot: $chatbot,
                session: $session,
                userText: $this->text,
                actorUserId: $chatbot->agent->user_id
                    ?? Team::where('id', $chatbot->team_id)->value('owner_id')
                    ?? $chatbot->team_id,
            );
        } catch (\Throwable $e) {
            Log::error('ProcessChatbotWebhookMessageJob: response service failed', [
                'chatbot_id' => $this->chatbotId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
