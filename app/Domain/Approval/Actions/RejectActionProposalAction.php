<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Models\User;
use RuntimeException;

class RejectActionProposalAction
{
    public function execute(ActionProposal $proposal, User $rejector, string $reason): ActionProposal
    {
        if ($proposal->team_id !== $rejector->current_team_id) {
            throw new RuntimeException('Rejector is not a member of the proposal team.');
        }

        if (! $proposal->isPending()) {
            throw new RuntimeException(
                "Proposal {$proposal->id} is not pending (status={$proposal->status->value}); cannot reject."
            );
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to reject a proposal.');
        }

        $proposal->update([
            'status' => ActionProposalStatus::Rejected,
            'decided_by_user_id' => $rejector->id,
            'decided_at' => now(),
            'decision_reason' => $reason,
        ]);

        return $proposal->refresh();
    }
}
