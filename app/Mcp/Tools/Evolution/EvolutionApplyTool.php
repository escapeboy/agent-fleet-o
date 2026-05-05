<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Actions\ApplyEvolutionProposalAction;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class EvolutionApplyTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'evolution_apply';

    protected string $description = 'Approve and apply an evolution proposal to update the agent. The proposal must be in pending or approved status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()
                ->description('The evolution proposal ID to apply')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $proposal = EvolutionProposal::withoutGlobalScopes()->where('team_id', $teamId)->findOrFail($request->get('proposal_id'));

        // Auto-approve if pending
        if ($proposal->status === EvolutionProposalStatus::Pending) {
            $proposal->update([
                'status' => EvolutionProposalStatus::Approved,
                'reviewed_at' => now(),
            ]);
        }

        $agent = app(ApplyEvolutionProposalAction::class)->execute(
            $proposal,
            auth()->id() ?? $proposal->agent->team->owner->id ?? '',
        );

        return Response::text(json_encode([
            'message' => 'Evolution proposal applied successfully',
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'goal' => $agent->goal,
                'backstory' => $agent->backstory,
                'personality' => $agent->personality,
            ],
        ]));
    }
}
