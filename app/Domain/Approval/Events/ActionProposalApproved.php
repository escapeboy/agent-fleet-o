<?php

namespace App\Domain\Approval\Events;

use App\Domain\Approval\Models\ActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after an ActionProposal is approved. Listeners can use this to
 * dispatch the deferred execution job.
 */
class ActionProposalApproved
{
    use Dispatchable;

    public function __construct(public readonly ActionProposal $proposal) {}
}
