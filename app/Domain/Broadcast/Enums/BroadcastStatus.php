<?php

namespace App\Domain\Broadcast\Enums;

/**
 * Lifecycle of a broadcast: draft → pending_approval → approved → sending → sent.
 */
enum BroadcastStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
