<?php

namespace App\Domain\Signal\Enums;

enum SignalStatus: string
{
    case Received = 'received';
    case Triaged = 'triaged';
    case InProgress = 'in_progress';
    case DelegatedToAgent = 'delegated_to_agent';
    case AgentFixing = 'agent_fixing';
    case Review = 'review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Triaged => 'Triaged',
            self::InProgress => 'In Progress',
            self::DelegatedToAgent => 'Delegated to Agent',
            self::AgentFixing => 'Agent Fixing',
            self::Review => 'Review',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received => 'blue',
            self::Triaged => 'yellow',
            self::InProgress => 'orange',
            self::DelegatedToAgent => 'purple',
            self::AgentFixing => 'purple',
            self::Review => 'indigo',
            self::Resolved => 'green',
            self::Dismissed => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Dismissed], true);
    }
}
