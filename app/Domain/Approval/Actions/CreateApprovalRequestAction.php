<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalMode;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Models\GlobalSetting;

class CreateApprovalRequestAction
{
    public function execute(
        Experiment $experiment,
        ?OutboundProposal $outboundProposal = null,
        array $context = [],
        ApprovalMode $mode = ApprovalMode::InLoop,
        ?int $interventionWindowSeconds = null,
    ): ApprovalRequest {
        $team = $experiment->team_id ? Team::withoutGlobalScopes()->find($experiment->team_id) : null;
        $teamTimeout = $team?->settings['approval_timeout_hours'] ?? null;
        $defaultTimeout = $experiment->constraints['approval_timeout_hours']
            ?? $teamTimeout
            ?? GlobalSetting::get('approval_timeout_hours', 48);

        // on_loop: default intervention window is 1 hour if not specified
        $windowSeconds = $mode === ApprovalMode::OnLoop
            ? ($interventionWindowSeconds ?? 3600)
            : null;

        return ApprovalRequest::withoutGlobalScopes()->create([
            'experiment_id' => $experiment->id,
            'team_id' => $experiment->team_id,
            'outbound_proposal_id' => $outboundProposal?->id,
            'status' => ApprovalStatus::Pending,
            'mode' => $mode,
            'intervention_window_seconds' => $windowSeconds,
            'context' => array_merge($context, [
                'experiment_title' => $experiment->title,
                'experiment_thesis' => $experiment->thesis,
                'iteration' => $experiment->current_iteration,
            ]),
            'expires_at' => now()->addHours($defaultTimeout),
        ]);
    }
}
