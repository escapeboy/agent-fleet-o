<?php

namespace App\Domain\Approval\Listeners;

use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Jobs\ExecuteActionProposalJob;

class DispatchActionProposalExecution
{
    public function handle(ActionProposalApproved $event): void
    {
        ExecuteActionProposalJob::dispatch($event->proposal->id);
    }
}
