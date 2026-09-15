<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Models\ActionProposal;
use App\Models\User;
use RuntimeException;

class ApproveActionProposalAction
{
    public function execute(ActionProposal $proposal, User $approver, ?string $reason = null): ActionProposal
    {
        if ($proposal->team_id !== $approver->current_team_id) {
            throw new RuntimeException('Approver is not a member of the proposal team.');
        }

        if (! $proposal->isPending()) {
            throw new RuntimeException(
                "Proposal {$proposal->id} is not pending (status={$proposal->status->value}); cannot approve.",
            );
        }

        // Conditional on the row still being pending, so two concurrent decisions
        // (or a decision racing the timeout settlement) cannot both apply.
        $updated = ActionProposal::query()
            ->withoutGlobalScopes()
            ->whereKey($proposal->id)
            ->where('team_id', $proposal->team_id)
            ->where('status', ActionProposalStatus::Pending->value)
            ->update([
                'status' => ActionProposalStatus::Approved->value,
                'decided_by_user_id' => $approver->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

        if ($updated !== 1) {
            throw new RuntimeException("Proposal {$proposal->id} is no longer pending; cannot approve.");
        }

        $fresh = $proposal->refresh();

        // Fires after the status flip so listeners (executor dispatcher,
        // future webhooks, etc.) see a coherent approved row.
        ActionProposalApproved::dispatch($fresh);

        return $fresh;
    }
}
