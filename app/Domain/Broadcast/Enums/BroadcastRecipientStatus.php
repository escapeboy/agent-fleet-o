<?php

namespace App\Domain\Broadcast\Enums;

/**
 * Per-recipient delivery state within a broadcast.
 */
enum BroadcastRecipientStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';
}
