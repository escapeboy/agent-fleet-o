<?php

namespace App\Domain\Chatbot\Contracts;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;

/**
 * Contract for answering a chatbot user message.
 *
 * Owned by FleetQ. Bound to ChatbotResponseService by default; downstream
 * layers (e.g. the Barsy plugin) may rebind this interface to substitute
 * their own answering pipeline per deployment.
 */
interface ChatbotResponderInterface
{
    /**
     * Process a user message and return the assistant response.
     *
     * @return array{message: ChatbotMessage, escalated: bool, reply: string|null}
     */
    public function handle(Chatbot $chatbot, ChatbotSession $session, string $userText, string $actorUserId): array;
}
