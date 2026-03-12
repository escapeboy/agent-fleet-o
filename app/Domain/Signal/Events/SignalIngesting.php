<?php

namespace App\Domain\Signal\Events;

/**
 * Fired before a signal is ingested.
 *
 * Listeners can mutate $payload or call $event->cancel() to reject the signal.
 */
class SignalIngesting
{
    public bool $cancel = false;

    public ?string $cancelReason = null;

    public function __construct(
        public readonly string $sourceType,
        public readonly string $sourceIdentifier,
        public array $payload,
        public array $tags = [],
    ) {}

    public function cancel(string $reason = 'Rejected by plugin'): void
    {
        $this->cancel = true;
        $this->cancelReason = $reason;
    }
}
