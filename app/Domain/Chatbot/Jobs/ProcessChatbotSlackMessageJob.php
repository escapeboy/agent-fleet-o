<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessChatbotSlackMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $chatbotId,
        public readonly string $channelId,
        public readonly string $slackUserId,
        public readonly string $slackChannelId,
        public readonly string $text,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(ChatbotResponseService $responseService): void
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
            Log::warning('ProcessChatbotSlackMessageJob: no bot_token in channel config', [
                'chatbot_id' => $this->chatbotId,
            ]);

            return;
        }

        // Session key: user_id:channel_id for per-conversation context
        $externalUserId = "{$this->slackUserId}:{$this->slackChannelId}";

        $session = ChatbotSession::withoutGlobalScopes()
            ->where('chatbot_id', $chatbot->id)
            ->where('channel', 'slack')
            ->where('external_user_id', $externalUserId)
            ->latest('last_activity_at')
            ->first();

        if (! $session || $session->last_activity_at?->diffInHours(now()) > 24) {
            $session = ChatbotSession::create([
                'chatbot_id' => $chatbot->id,
                'team_id' => $chatbot->team_id,
                'channel' => 'slack',
                'external_user_id' => $externalUserId,
                'metadata' => [
                    'slack_user_id' => $this->slackUserId,
                    'slack_channel_id' => $this->slackChannelId,
                ],
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        }

        try {
            $result = $responseService->handle(
                chatbot: $chatbot,
                session: $session,
                userText: $this->text,
                actorUserId: $chatbot->agent?->user_id
                    ?? Team::where('id', $chatbot->team_id)->value('owner_id')
                    ?? $chatbot->team_id,
            );
        } catch (\Throwable $e) {
            Log::error('ProcessChatbotSlackMessageJob: response error', [
                'chatbot_id' => $this->chatbotId,
                'error' => $e->getMessage(),
            ]);
            $this->postToSlack($botToken, $this->slackChannelId, 'I encountered an error. Please try again.');

            return;
        }

        $reply = $result['reply'];

        if ($result['escalated'] || $reply === null) {
            $reply = $chatbot->fallback_message ?? 'I need a moment to verify this response. Please wait.';
        }

        $this->postToSlack($botToken, $this->slackChannelId, $reply);
    }

    private function postToSlack(string $botToken, string $channelId, string $text): void
    {
        try {
            Http::timeout(15)
                ->withToken($botToken)
                ->post('https://slack.com/api/chat.postMessage', [
                    'channel' => $channelId,
                    'text' => $text,
                    'mrkdwn' => true,
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProcessChatbotSlackMessageJob: Slack API error', [
                'chatbot_id' => $this->chatbotId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
