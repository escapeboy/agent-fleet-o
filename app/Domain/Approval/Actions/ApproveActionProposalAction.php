<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Tool\Services\ToolApprovalGate;
use App\Domain\Tool\Services\ToolDefinitionPinner;
use App\Models\User;
use RuntimeException;

class ApproveActionProposalAction
{
    /**
     * @param  array<mixed>|null  $editedArguments  agent_tool_call only: arguments the approver
     *                                                      corrected; the replay runs with these instead of the model's
     */
    public function execute(ActionProposal $proposal, User $approver, ?string $reason = null, ?array $editedArguments = null): ActionProposal
    {
        if ($proposal->team_id !== $approver->current_team_id) {
            throw new RuntimeException('Approver is not a member of the proposal team.');
        }

        if ($proposal->target_type === ToolDefinitionPinner::TARGET_TYPE && ! ToolDefinitionPinner::canApprove($approver, (string) $proposal->team_id)) {
            throw new RuntimeException('Only a team owner or admin can approve a change to MCP tool definitions.');
        }

        if ($editedArguments !== null) {
            if ($proposal->target_type !== ToolApprovalGate::TARGET_TYPE) {
                throw new RuntimeException('Arguments can only be edited when approving an agent tool call.');
            }
            if ($editedArguments !== [] && array_is_list($editedArguments)) {
                throw new RuntimeException('Edited arguments must be an object of parameter names to values.');
            }
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
                ...($editedArguments !== null ? ['payload' => json_encode([
                    ...$proposal->payload,
                    'edited_arguments' => (object) $editedArguments,
                    'edited_by_user_id' => $approver->id,
                ])] : []),
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
