<?php

namespace App\Domain\Agent\Events;

use App\Domain\Agent\Models\Agent;

/**
 * Fired before an agent is executed.
 *
 * Listeners can mutate $context (pass-by-reference) to inject additional context,
 * or call $event->cancel() to abort execution.
 */
class AgentExecuting
{
    public bool $cancel = false;

    public ?string $cancelReason = null;

    public function __construct(
        public readonly Agent $agent,
        public array $context,
    ) {}

    /**
     * Abort the agent execution. The action will return a failed execution.
     */
    public function cancel(string $reason = 'Cancelled by plugin'): void
    {
        $this->cancel = true;
        $this->cancelReason = $reason;
    }
}
