<?php

namespace App\Domain\Evolution\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;

class ApplyEvolutionProposalAction
{
    public function execute(EvolutionProposal $proposal, string $userId): Agent
    {
        if ($proposal->status !== EvolutionProposalStatus::Approved) {
            throw new \RuntimeException('Only approved proposals can be applied.');
        }

        $agent = $proposal->agent;
        $changes = $proposal->proposed_changes;

        $updateData = [];

        if (! empty($changes['goal'])) {
            $updateData['goal'] = $changes['goal'];
        }

        if (! empty($changes['backstory'])) {
            $updateData['backstory'] = $changes['backstory'];
        }

        if (! empty($changes['personality'])) {
            $current = $agent->personality ?? [];
            $updateData['personality'] = array_merge($current, array_filter($changes['personality'], fn ($v) => $v !== null));
        }

        if (! empty($changes['constraints'])) {
            $current = $agent->constraints ?? [];
            $updateData['constraints'] = array_merge($current, $changes['constraints']);
        }

        if (! empty($updateData)) {
            $agent->update($updateData);
        }

        $proposal->update([
            'status' => EvolutionProposalStatus::Applied,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);

        return $agent->fresh();
    }
}
