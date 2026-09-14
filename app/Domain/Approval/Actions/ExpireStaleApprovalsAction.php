<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Tool\Enums\ApprovalTimeoutAction;
use App\Domain\Tool\Services\ToolApprovalGate;
use Illuminate\Support\Facades\Log;

class ExpireStaleApprovalsAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(): int
    {
        $staleRequests = ApprovalRequest::where('status', ApprovalStatus::Pending)
            ->where('expires_at', '<', now())
            ->with('experiment')
            ->get();

        $expired = 0;

        foreach ($staleRequests as $request) {
            $request->update([
                'status' => ApprovalStatus::Expired,
                'reviewed_at' => now(),
            ]);

            // Chatbot escalation approvals have no experiment — skip experiment-specific steps
            if ($request->experiment_id === null) {
                $expired++;

                continue;
            }

            // Expire associated proposals
            $request->experiment->outboundProposals()
                ->where('status', OutboundProposalStatus::PendingApproval)
                ->update(['status' => OutboundProposalStatus::Expired]);

            // Transition experiment to expired
            if ($request->experiment->status === ExperimentStatus::AwaitingApproval) {
                try {
                    $this->transition->execute(
                        experiment: $request->experiment,
                        toState: ExperimentStatus::Expired,
                        reason: 'Approval request expired',
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to expire experiment', [
                        'experiment_id' => $request->experiment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $expired++;
        }

        return $expired + $this->settleAgentToolCallProposals();
    }

    /**
     * Agent tool calls held for approval (ToolApprovalGate) whose approval window
     * has passed. The tool's approval_timeout_action decides:
     *  - deny, skip: expire. The call never ran, so both leave the agent without it.
     *  - allow: approve and queue the replay, unless a versioned agent policy
     *    governed the proposal (a policy that asked for a human is not overridden).
     * The conditional update keeps a human decision made at the same moment.
     */
    private function settleAgentToolCallProposals(): int
    {
        $settled = 0;

        $due = ActionProposal::withoutGlobalScopes()
            ->where('target_type', ToolApprovalGate::TARGET_TYPE)
            ->where('status', ActionProposalStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->limit(500)
            ->get();

        foreach ($due as $proposal) {
            $allow = ($proposal->payload['timeout_action'] ?? null) === ApprovalTimeoutAction::Allow->value
                && $proposal->agent_policy_version_id === null;

            $changes = $allow
                ? [
                    'status' => ActionProposalStatus::Approved->value,
                    'expires_at' => null,
                    'decided_at' => now(),
                    'decision_reason' => 'Approved automatically: no decision within the approval window and the tool timeout action is "allow".',
                ]
                : [
                    'status' => ActionProposalStatus::Expired->value,
                    'decided_at' => now(),
                    'decision_reason' => 'Expired: no decision within the approval window. The tool call did not run.',
                ];

            $updated = ActionProposal::withoutGlobalScopes()
                ->whereKey($proposal->id)
                ->where('status', ActionProposalStatus::Pending->value)
                ->update($changes);

            if ($updated !== 1) {
                continue;
            }

            $settled++;

            if ($allow) {
                ActionProposalApproved::dispatch($proposal->refresh());
            }
        }

        return $settled;
    }
}
