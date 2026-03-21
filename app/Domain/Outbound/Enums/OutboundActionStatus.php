<?php

namespace App\Domain\Outbound\Enums;

enum OutboundActionStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Bounced = 'bounced';
}
