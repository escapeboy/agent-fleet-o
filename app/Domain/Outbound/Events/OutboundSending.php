<?php

namespace App\Domain\Outbound\Events;

use App\Domain\Outbound\Models\OutboundProposal;

/**
 * Fired before an outbound proposal is sent.
 *
 * Listeners can call $event->cancel() to prevent delivery.
 */
class OutboundSending
{
    public bool $cancel = false;

    public ?string $cancelReason = null;

    public function __construct(
        public readonly OutboundProposal $proposal,
    ) {}

    public function cancel(string $reason = 'Cancelled by plugin'): void
    {
        $this->cancel = true;
        $this->cancelReason = $reason;
    }
}
