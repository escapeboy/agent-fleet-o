<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\CrewAgentMessage;
use App\Domain\Crew\Models\CrewExecution;

class SendAgentMessageAction
{
    /**
     * Send a message between agents within a crew execution.
     */
    public function execute(
        CrewExecution $execution,
        string $messageType,
        string $content,
        ?Agent $sender = null,
        ?Agent $recipient = null,
        int $round = 0,
        ?string $parentMessageId = null,
    ): CrewAgentMessage {
        return CrewAgentMessage::create([
            'team_id' => $execution->team_id,
            'crew_execution_id' => $execution->id,
            'sender_agent_id' => $sender?->id,
            'recipient_agent_id' => $recipient?->id,
            'parent_message_id' => $parentMessageId,
            'message_type' => $messageType,
            'round' => $round,
            'content' => $content,
            'is_read' => false,
        ]);
    }
}
