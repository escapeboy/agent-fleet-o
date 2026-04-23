<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Listeners;

use App\Domain\AgentChatProtocol\Events\ChatMessageDispatched;
use App\Domain\AgentChatProtocol\Events\ChatMessageReceived;
use Illuminate\Support\Facades\Log;

class LogProtocolTransaction
{
    public function handleReceived(ChatMessageReceived $event): void
    {
        Log::info('AgentChatProtocol inbound', [
            'message_id' => $event->message->id,
            'msg_id' => $event->message->msg_id,
            'team_id' => $event->message->team_id,
            'session_id' => $event->message->session_id,
            'agent_id' => $event->message->agent_id,
            'message_type' => $event->message->message_type->value,
        ]);
    }

    public function handleDispatched(ChatMessageDispatched $event): void
    {
        Log::info('AgentChatProtocol outbound', [
            'message_id' => $event->message->id,
            'msg_id' => $event->message->msg_id,
            'team_id' => $event->message->team_id,
            'external_agent_id' => $event->message->external_agent_id,
            'message_type' => $event->message->message_type->value,
        ]);
    }
}
