<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Models\ActionProposal;
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
class ActionProposalGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'action_proposal_get';

    protected string $description = 'Get a single action proposal with full payload + lineage chain.';

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

        return Response::text(json_encode([
            'id' => $proposal->id,
            'team_id' => $proposal->team_id,
            'target_type' => $proposal->target_type,
            'target_id' => $proposal->target_id,
            'summary' => $proposal->summary,
            'payload' => $proposal->payload,
            'lineage' => $proposal->lineage,
            'risk_level' => $proposal->risk_level,
            'status' => $proposal->status->value,
            'actor_user_id' => $proposal->actor_user_id,
            'actor_agent_id' => $proposal->actor_agent_id,
            'decided_by_user_id' => $proposal->decided_by_user_id,
            'decided_at' => $proposal->decided_at?->toIso8601String(),
            'decision_reason' => $proposal->decision_reason,
            'expires_at' => $proposal->expires_at?->toIso8601String(),
            'created_at' => $proposal->created_at?->toIso8601String(),
        ]));
    }
}
