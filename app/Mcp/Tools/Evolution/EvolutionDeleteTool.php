<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Models\EvolutionProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class EvolutionDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'evolution_proposal_delete';

    protected string $description = 'Delete an evolution proposal.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()->description('The evolution proposal ID to delete.')->required(),
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
            ->find($request->get('proposal_id'));

        if (! $proposal) {
            return $this->notFoundError('evolution proposal');
        }

        $proposal->delete();

        return Response::text(json_encode([
            'success' => true,
            'id' => $request->get('proposal_id'),
        ]));
    }
}
