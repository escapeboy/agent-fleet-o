<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RejectEvolutionProposalTool implements Tool
{
    public function name(): string
    {
        return 'reject_evolution_proposal';
    }

    public function description(): string
    {
        return 'Reject a pending or approved evolution proposal, preventing it from being applied to the agent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()->required()->description('The evolution proposal UUID'),
            'reason' => $schema->string()->description('Optional reason for rejection'),
        ];
    }

    public function handle(Request $request): string
    {
        $proposal = EvolutionProposal::find($request->get('proposal_id'));
        if (! $proposal) {
            return json_encode(['error' => 'Evolution proposal not found']);
        }

        if (! in_array($proposal->status, [EvolutionProposalStatus::Pending, EvolutionProposalStatus::Approved])) {
            return json_encode(['error' => "Cannot reject proposal in '{$proposal->status->value}' status."]);
        }

        try {
            $proposal->update([
                'status' => EvolutionProposalStatus::Rejected,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            return json_encode([
                'success' => true,
                'proposal_id' => $proposal->id,
                'status' => 'rejected',
                'reason' => $request->get('reason'),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
