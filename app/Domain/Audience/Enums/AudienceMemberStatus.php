<?php

namespace App\Domain\Audience\Enums;

/**
 * Subscription state of a contact within an audience.
 */
enum AudienceMemberStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case Pending = 'pending';
}
