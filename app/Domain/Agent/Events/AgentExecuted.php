<?php

namespace App\Domain\Agent\Events;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;

/**
 * Fired after an agent execution completes (success or failure).
 */
class AgentExecuted
{
    public function __construct(
        public readonly Agent $agent,
        public readonly AgentExecution $execution,
        public readonly bool $succeeded,
    ) {}
}
