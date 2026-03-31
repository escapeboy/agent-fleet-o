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

class ProcessChatbotTicketMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $chatbotId,
        public readonly string $channelId,
        public readonly string $ticketId,
        public readonly string $requester,
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

        // Each ticket gets its own session (one conversation per ticket)
        $session = ChatbotSession::withoutGlobalScopes()
            ->where('chatbot_id', $chatbot->id)
            ->where('channel', 'ticket_system')
            ->where('external_user_id', $this->ticketId)
            ->first();

        if (! $session) {
            $session = ChatbotSession::create([
                'chatbot_id' => $chatbot->id,
                'team_id' => $chatbot->team_id,
                'channel' => 'ticket_system',
                'external_user_id' => $this->ticketId,
                'metadata' => [
                    'ticket_id' => $this->ticketId,
                    'requester' => $this->requester,
                ],
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        }

        try {
            $responseService->handle(
                chatbot: $chatbot,
                session: $session,
                userText: $this->text,
                actorUserId: $chatbot->agent?->user_id
                    ?? Team::where('id', $chatbot->team_id)->value('owner_id')
                    ?? $chatbot->team_id,
            );
        } catch (\Throwable $e) {
            Log::error('ProcessChatbotTicketMessageJob: response service failed', [
                'chatbot_id' => $this->chatbotId,
                'ticket_id' => $this->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
