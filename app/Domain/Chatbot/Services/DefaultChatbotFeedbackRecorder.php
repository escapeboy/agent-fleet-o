<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Chatbot\Contracts\ChatbotFeedbackRecorderInterface;
use App\Domain\Chatbot\Models\ChatbotMessage;

/**
 * Harmless default recorder: stamps the vote onto ChatbotMessage::feedback.
 *
 * Runs in the unauthenticated Telegram webhook context (no current team), so
 * the lookup bypasses the TeamScope global scope — mirroring the channel/session
 * resolution in ProcessChatbotTelegramMessageJob.
 */
class DefaultChatbotFeedbackRecorder implements ChatbotFeedbackRecorderInterface
{
    public function record(string $messageId, string $vote): void
    {
        $message = ChatbotMessage::withoutGlobalScopes()->find($messageId);

        if ($message === null) {
            return;
        }

        $message->feedback = $vote;
        $message->save();
    }
}
