<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Actions\RejectActionProposalAction;
use App\Domain\Approval\Models\ActionProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class ActionProposalRejectTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'action_proposal_reject';

    protected string $description = 'Reject a pending action proposal. Reason is required.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()->required(),
            'reason' => $schema->string()->required()->description('Rejection reason'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'proposal_id' => 'required|string',
            'reason' => 'required|string|min:1|max:1000',
        ]);

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

        $user = auth()->user();
        if (! $user) {
            return $this->permissionDeniedError('Authenticated user required to reject.');
        }

        app(RejectActionProposalAction::class)->execute($proposal, $user, $validated['reason']);

        return Response::text(json_encode([
            'success' => true,
            'proposal_id' => $proposal->id,
            'status' => 'rejected',
        ]));
    }
}
