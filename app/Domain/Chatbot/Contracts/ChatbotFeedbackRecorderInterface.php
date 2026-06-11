<?php

namespace App\Domain\Chatbot\Contracts;

/**
 * Contract for recording a per-answer vote on a chatbot message.
 *
 * Owned by FleetQ. Bound to a harmless default recorder that stamps the vote
 * onto ChatbotMessage::feedback; downstream layers (e.g. the Barsy plugin)
 * rebind this interface to route the vote into their own feedback pipeline.
 */
interface ChatbotFeedbackRecorderInterface
{
    /**
     * Record a vote against the given assistant message.
     *
     * @param  'thumbs_up'|'thumbs_down'  $vote
     */
    public function record(string $messageId, string $vote): void;
}
