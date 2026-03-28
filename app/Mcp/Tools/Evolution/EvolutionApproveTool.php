<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class EvolutionApproveTool extends Tool
{
    protected string $name = 'evolution_approve';

    protected string $description = 'Approve a pending evolution proposal without immediately applying it. After approval, use evolution_apply to apply the changes to the agent, or evolution_reject to dismiss. This enables human-in-the-loop review: analyze → approve → apply.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()
                ->description('The evolution proposal UUID to approve')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'proposal_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $proposal = EvolutionProposal::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['proposal_id']);

        if (! $proposal) {
            return Response::error('Evolution proposal not found.');
        }

        if ($proposal->status === EvolutionProposalStatus::Applied) {
            return Response::error('This proposal has already been applied and cannot be re-approved.');
        }

        if ($proposal->status === EvolutionProposalStatus::Rejected) {
            return Response::error('This proposal has been rejected. Analyze a new execution to generate a fresh proposal.');
        }

        if ($proposal->status === EvolutionProposalStatus::Approved) {
            return Response::text(json_encode([
                'success' => true,
                'proposal_id' => $proposal->id,
                'status' => $proposal->status->value,
                'message' => 'Proposal is already approved. Use evolution_apply to apply the changes.',
                'agent_id' => $proposal->agent_id,
            ]));
        }

        $proposal->update([
            'status' => EvolutionProposalStatus::Approved,
            'reviewed_at' => now(),
        ]);

        return Response::text(json_encode([
            'success' => true,
            'proposal_id' => $proposal->id,
            'status' => EvolutionProposalStatus::Approved->value,
            'message' => 'Proposal approved. Use evolution_apply to apply the changes to the agent.',
            'agent_id' => $proposal->agent_id,
        ]));
    }
}
