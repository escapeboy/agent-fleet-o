<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
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

        return $expired;
    }
}
