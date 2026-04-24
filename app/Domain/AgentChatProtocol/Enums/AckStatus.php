<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum AckStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Completed = 'completed';
    case Error = 'error';
}
