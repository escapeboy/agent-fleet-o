<?php

namespace App\Domain\Signal\Enums;

enum SignalInferredIntent: string
{
    case ActionCompleted = 'action_completed';
    case BlockerRaised = 'blocker_raised';
    case StaleDeal = 'stale_deal';
    case InformationRequest = 'information_request';
    case PositiveEngagement = 'positive_engagement';
    case Neutral = 'neutral';

    public function label(): string
    {
        return match ($this) {
            self::ActionCompleted => 'Action completed',
            self::BlockerRaised => 'Blocker raised',
            self::StaleDeal => 'Stale deal',
            self::InformationRequest => 'Information request',
            self::PositiveEngagement => 'Positive engagement',
            self::Neutral => 'Neutral',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
