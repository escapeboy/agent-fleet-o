<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum MessageType: string
{
    case ChatMessage = 'chat_message';
    case ChatAcknowledgement = 'chat_acknowledgement';
    case StructuredOutputRequest = 'structured_output_request';
    case StructuredOutputResponse = 'structured_output_response';
}
