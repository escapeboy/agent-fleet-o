<?php

namespace App\Domain\Outbound\Enums;

enum OutboundChannel: string
{
    case Email = 'email';
    case Telegram = 'telegram';
    case Slack = 'slack';
    case Webhook = 'webhook';
}
