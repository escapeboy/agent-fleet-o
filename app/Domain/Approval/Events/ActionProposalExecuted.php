<?php

namespace App\Domain\Approval\Events;

use App\Domain\Approval\Models\ActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by ExecuteActionProposalJob after persisting the execution outcome
 * (Executed or ExecutionFailed). Listeners can react to the result —
 * e.g., append the outcome back to the originating assistant conversation
 * so the agent's next turn sees what happened.
 */
class ActionProposalExecuted
{
    use Dispatchable;

    public function __construct(
        public readonly ActionProposal $proposal,
        public readonly bool $succeeded,
    ) {}
}
