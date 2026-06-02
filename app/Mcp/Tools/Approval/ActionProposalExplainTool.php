<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\ProposalExplainResolver;
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
class ActionProposalExplainTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'action_proposal_explain';

    protected string $description = 'Explain why a proposal was routed the way it was: the pinned agent-policy version + rules, the rubric breakdown, the policy verdict, and the lineage. Reproducible after the policy later changes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()->required()->description('The action proposal UUID'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['proposal_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $proposal = ActionProposal::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['proposal_id']);

        if (! $proposal) {
            return $this->notFoundError('action proposal');
        }

        return Response::text(json_encode(
            app(ProposalExplainResolver::class)->explain($proposal),
        ));
    }
}
