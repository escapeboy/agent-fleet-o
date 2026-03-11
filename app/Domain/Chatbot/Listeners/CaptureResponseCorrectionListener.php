<?php

namespace App\Domain\Chatbot\Listeners;

use App\Domain\Chatbot\Enums\LearningEntryStatus;
use App\Domain\Chatbot\Events\ChatbotResponseApprovedEvent;
use App\Domain\Chatbot\Models\ChatbotLearningEntry;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use Illuminate\Contracts\Queue\ShouldQueue;

class CaptureResponseCorrectionListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(ChatbotResponseApprovedEvent $event): void
    {
        $message = ChatbotMessage::find($event->chatbotMessageId);

        if (! $message) {
            return;
        }

        // Only capture when the operator actually modified the draft
        if ($message->draft_content === null || $message->draft_content === $event->approvedContent) {
            return;
        }

        $session = ChatbotSession::find($event->sessionId);

        // Retrieve the original user message from the session
        $userMessage = ChatbotMessage::where('session_id', $event->sessionId)
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->value('content');

        ChatbotLearningEntry::create([
            'chatbot_id' => $message->chatbot_id,
            'session_id' => $event->sessionId,
            'message_id' => $event->chatbotMessageId,
            'team_id' => $message->team_id,
            'user_message' => $userMessage ?? '',
            'original_response' => $message->draft_content,
            'corrected_response' => $event->approvedContent,
            'model_config' => $message->metadata ?? null,
            'status' => LearningEntryStatus::PendingReview,
        ]);
    }
}
