<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use App\Domain\Telegram\Actions\SendTelegramReplyAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessChatbotTelegramMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $chatbotId,
        public readonly string $channelId,
        public readonly string $chatId,
        public readonly string $text,
        public readonly ?string $username = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(ChatbotResponseService $responseService, SendTelegramReplyAction $sendReply): void
    {
        $chatbot = Chatbot::find($this->chatbotId);

        if (! $chatbot || ! $chatbot->status->isActive()) {
            return;
        }

        $channel = ChatbotChannel::withoutGlobalScopes()->find($this->channelId);

        if (! $channel || ! $channel->is_active) {
            return;
        }

        $botToken = $channel->config['bot_token'] ?? null;

        if (! $botToken) {
            Log::warning('ProcessChatbotTelegramMessageJob: no bot_token in channel config', [
                'chatbot_id' => $this->chatbotId,
                'channel_id' => $this->channelId,
            ]);

            return;
        }

        // Find or create session — start a new one if the last activity was > 24 hours ago
        $session = ChatbotSession::withoutGlobalScopes()
            ->where('chatbot_id', $chatbot->id)
            ->where('channel', 'telegram')
            ->where('external_user_id', $this->chatId)
            ->latest('last_activity_at')
            ->first();

        if (! $session || $session->last_activity_at?->diffInHours(now()) > 24) {
            $session = ChatbotSession::create([
                'chatbot_id' => $chatbot->id,
                'team_id' => $chatbot->team_id,
                'channel' => 'telegram',
                'external_user_id' => $this->chatId,
                'metadata' => ['username' => $this->username],
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        }

        try {
            $result = $responseService->handle(
                chatbot: $chatbot,
                session: $session,
                userText: $this->text,
                actorUserId: $chatbot->team_id, // use team_id as actor for budget tracking
            );
        } catch (\Throwable $e) {
            Log::error('ProcessChatbotTelegramMessageJob: response error', [
                'chatbot_id' => $this->chatbotId,
                'error' => $e->getMessage(),
            ]);
            $sendReply->execute($botToken, $this->chatId, 'I encountered an error processing your message. Please try again.');

            return;
        }

        $reply = $result['reply'];

        if ($result['escalated'] || $reply === null) {
            $reply = $chatbot->fallback_message ?? 'I need a moment to verify this response. Please wait.';
        }

        $sendReply->execute($botToken, $this->chatId, $reply);
    }
}
