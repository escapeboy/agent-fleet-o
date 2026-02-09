<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundProposal;

class CreateApprovalRequestAction
{
    public function execute(
        Experiment $experiment,
        ?OutboundProposal $outboundProposal = null,
        array $context = [],
    ): ApprovalRequest {
        $defaultTimeout = $experiment->constraints['approval_timeout_hours'] ?? 48;

        return ApprovalRequest::withoutGlobalScopes()->create([
            'experiment_id' => $experiment->id,
            'team_id' => $experiment->team_id,
            'outbound_proposal_id' => $outboundProposal?->id,
            'status' => ApprovalStatus::Pending,
            'context' => array_merge($context, [
                'experiment_title' => $experiment->title,
                'experiment_thesis' => $experiment->thesis,
                'iteration' => $experiment->current_iteration,
            ]),
            'expires_at' => now()->addHours($defaultTimeout),
        ]);
    }
}
