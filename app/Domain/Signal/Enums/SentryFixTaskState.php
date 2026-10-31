<?php

namespace App\Domain\Signal\Enums;

/**
 * Lifecycle of a SentryFixTask handled by an external fix worker.
 *
 * The allowed-transitions table is the single source of truth: the events API
 * rejects (422) everything not listed here. Terminal states have no exits.
 */
enum SentryFixTaskState: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Fixing = 'fixing';
    case PrOpen = 'pr_open';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Merging = 'merging';
    case Deploying = 'deploying';
    case Watching = 'watching';
    case Done = 'done';
    case Reverted = 'reverted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Escalated = 'escalated';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Claimed, self::Failed, self::Escalated],
            self::Claimed => [self::Queued, self::Fixing, self::PrOpen, self::Failed, self::Escalated],
            self::Fixing => [self::Queued, self::PrOpen, self::AwaitingApproval, self::Failed, self::Escalated],
            self::PrOpen => [self::Fixing, self::AwaitingApproval, self::Failed, self::Escalated],
            self::AwaitingApproval => [self::Approved, self::Rejected, self::Failed, self::Escalated],
            self::Approved => [self::Merging, self::Rejected, self::Failed, self::Escalated],
            self::Merging => [self::Deploying, self::Watching, self::Done, self::Reverted, self::Failed, self::Escalated],
            self::Deploying => [self::Watching, self::Done, self::Reverted, self::Failed, self::Escalated],
            self::Watching => [self::Done, self::Reverted, self::Failed, self::Escalated],
            self::Done, self::Reverted, self::Rejected, self::Failed, self::Escalated => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        // Re-posting the current non-terminal state is a progress/heartbeat event.
        if ($next === $this) {
            return ! $this->isTerminal();
        }

        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /**
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Done, self::Reverted, self::Rejected, self::Failed, self::Escalated];
    }

    /**
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::terminal());
    }

    /**
     * States in which the task is waiting for a person or for pick-up, so no
     * worker lease runs.
     */
    public function isLeaseFree(): bool
    {
        return in_array($this, [self::Queued, self::AwaitingApproval, self::Approved], true) || $this->isTerminal();
    }
}
