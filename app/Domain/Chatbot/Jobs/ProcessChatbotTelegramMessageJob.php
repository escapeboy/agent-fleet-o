<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Contracts\ChatbotResponderInterface;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Shared\Models\Team;
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
        public readonly ?string $sessionExternalId = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(ChatbotResponderInterface $responseService, SendTelegramReplyAction $sendReply): void
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

        // Session identity: in groups this is "<chatId>:<userId>" so each member
        // gets their own conversation (no context bleed); in private chats it is
        // just "<chatId>" (back-compat). Replies always go back to $chatId.
        $externalUserId = $this->sessionExternalId ?? $this->chatId;

        // Find or create session — start a new one if the last activity was > 24 hours ago
        $session = ChatbotSession::withoutGlobalScopes()
            ->where('chatbot_id', $chatbot->id)
            ->where('channel', 'telegram')
            ->where('external_user_id', $externalUserId)
            ->latest('last_activity_at')
            ->first();

        if (! $session || $session->last_activity_at?->diffInHours(now()) > 24) {
            $session = ChatbotSession::create([
                'chatbot_id' => $chatbot->id,
                'team_id' => $chatbot->team_id,
                'channel' => 'telegram',
                'external_user_id' => $externalUserId,
                'metadata' => ['username' => $this->username],
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        }

        try {
            $team = Team::find($chatbot->team_id);
            $actorUserId = $team->owner_id ?? $chatbot->team_id;

            $result = $responseService->handle(
                chatbot: $chatbot,
                session: $session,
                userText: $this->text,
                actorUserId: $actorUserId,
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
        $feedbackId = $result['feedback_message_id'] ?? null;

        if ($result['escalated'] || $reply === null) {
            $reply = $chatbot->fallback_message ?? 'I need a moment to verify this response. Please wait.';
            // No answer to vote on yet (escalated / pending) — suppress voting buttons.
            $feedbackId = null;
        }

        $sendReply->execute($botToken, $this->chatId, $reply, feedbackId: $feedbackId);
    }
}
