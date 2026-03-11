<?php

namespace App\Domain\Chatbot\Enums;

enum LearningEntryStatus: string
{
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Exported = 'exported';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending Review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Exported => 'Exported',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingReview => 'yellow',
            self::Accepted => 'green',
            self::Rejected => 'red',
            self::Exported => 'blue',
        };
    }
}
