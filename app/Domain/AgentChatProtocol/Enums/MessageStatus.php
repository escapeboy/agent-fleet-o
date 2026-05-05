<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Expired = 'expired';
}
