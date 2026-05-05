<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Models\EvolutionProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class EvolutionGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'evolution_proposal_get';

    protected string $description = 'Get details of a specific evolution proposal.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()->description('The evolution proposal ID.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $proposal = EvolutionProposal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('agent:id,name')
            ->find($request->get('proposal_id'));

        if (! $proposal) {
            return $this->notFoundError('evolution proposal');
        }

        return Response::text(json_encode([
            'id' => $proposal->id,
            'agent_id' => $proposal->agent_id,
            'agent_name' => $proposal->agent?->name,
            'status' => $proposal->status->value,
            'analysis' => $proposal->analysis,
            'proposed_changes' => $proposal->proposed_changes,
            'reasoning' => $proposal->reasoning,
            'confidence_score' => $proposal->confidence_score,
            'created_at' => $proposal->created_at,
            'updated_at' => $proposal->updated_at,
        ]));
    }
}
