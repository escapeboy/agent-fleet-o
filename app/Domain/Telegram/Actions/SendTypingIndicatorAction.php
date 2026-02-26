<?php

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTypingIndicatorAction
{
    /**
     * Send a "typing..." indicator to a Telegram chat.
     *
     * The indicator lasts 5 seconds on the user's screen.
     * For long-running operations, call this every 4 seconds.
     */
    public function execute(TelegramBot $bot, string $chatId): void
    {
        try {
            Http::timeout(3)
                ->post($bot->apiUrl('sendChatAction'), [
                    'chat_id' => $chatId,
                    'action' => 'typing',
                ]);
        } catch (\Throwable $e) {
            // Non-critical — typing indicator failure should not block message processing
            Log::debug('SendTypingIndicatorAction: failed to send typing indicator', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
