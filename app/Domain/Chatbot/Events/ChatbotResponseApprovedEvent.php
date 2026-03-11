<?php

namespace App\Domain\Chatbot\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatbotResponseApprovedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $chatbotMessageId,
        public readonly string $sessionId,
        public readonly string $approvedContent,
    ) {}
}
