<?php

namespace App\Domain\Marketplace\Enums;

enum MarketplaceStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending Review',
            self::Published => 'Published',
            self::Suspended => 'Suspended',
        };
    }
}
