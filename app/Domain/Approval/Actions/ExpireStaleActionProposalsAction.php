<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;

class ExpireStaleActionProposalsAction
{
    /**
     * Sweep pending proposals whose expires_at is in the past, mark expired.
     * Returns the number of rows updated.
     */
    public function execute(): int
    {
        return ActionProposal::query()
            ->where('status', ActionProposalStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => ActionProposalStatus::Expired->value,
            ]);
    }
}
