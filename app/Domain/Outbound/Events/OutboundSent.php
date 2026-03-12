<?php

namespace App\Domain\Outbound\Events;

use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;

/**
 * Fired after an outbound action has been recorded (send attempted).
 */
class OutboundSent
{
    public function __construct(
        public readonly OutboundProposal $proposal,
        public readonly OutboundAction $action,
        public readonly bool $succeeded,
    ) {}
}
