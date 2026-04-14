<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class EvolutionRejectTool extends Tool
{
    protected string $name = 'evolution_reject';

    protected string $description = 'Reject a pending or approved evolution proposal, preventing it from being applied to the agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()
                ->description('The evolution proposal UUID to reject')
                ->required(),
            'reason' => $schema->string()
                ->description('Optional reason for rejection'),
        ];
    }

    public function handle(Request $request): Response
    {
        $proposalId = $request->get('proposal_id');
        $reason = $request->get('reason');

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $proposal = EvolutionProposal::withoutGlobalScopes()->where('team_id', $teamId)->find($proposalId);

        if (! $proposal) {
            return Response::error("Evolution proposal {$proposalId} not found.");
        }

        if (! in_array($proposal->status, [EvolutionProposalStatus::Pending, EvolutionProposalStatus::Approved])) {
            return Response::error("Cannot reject proposal in '{$proposal->status->value}' status. Only pending or approved proposals can be rejected.");
        }

        $proposal->update([
            'status' => EvolutionProposalStatus::Rejected,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'reasoning' => $reason ? ($proposal->reasoning ? $proposal->reasoning."\n\nRejection reason: ".$reason : 'Rejection reason: '.$reason) : $proposal->reasoning,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'proposal_id' => $proposal->id,
            'agent_id' => $proposal->agent_id,
            'status' => EvolutionProposalStatus::Rejected->value,
            'rejected_at' => now()->toIso8601String(),
            'reason' => $reason,
        ]));
    }
}
