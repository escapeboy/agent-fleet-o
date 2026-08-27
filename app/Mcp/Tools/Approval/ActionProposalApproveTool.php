<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Actions\ApproveActionProposalAction;
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
class ActionProposalApproveTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'action_proposal_approve';

    protected string $description = 'Approve an action proposal. Marks it approved; the underlying action does not auto-execute in v1 — caller must re-invoke.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()->required(),
            'reason' => $schema->string()->description('Optional approval note'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'proposal_id' => 'required|string',
            'reason' => 'nullable|string|max:1000',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
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
            return $this->permissionDeniedError('Authenticated user required to approve.');
        }

        // Self-approval guard — MCP surface only.
        //
        // ActionProposal is the gate in front of an agent's own side effects
        // (git push, integration actions, governed tool calls). Over MCP the
        // caller and the proposer are frequently the SAME identity: stdio runs
        // as the team owner, so an agent can create a proposal and then approve
        // it in the next tool call, which defeats the gate entirely.
        //
        // Deliberately NOT placed in ApproveActionProposalAction: through the
        // web UI a person approving a proposal they themselves triggered is the
        // normal human-in-the-loop flow, and guarding the shared action would
        // break it. The UI stays the escape hatch for this case.
        if ($proposal->actor_user_id !== null && $proposal->actor_user_id === $user->id) {
            return $this->permissionDeniedError(
                'A proposal cannot be approved over MCP by the same identity that raised it. '
                .'Approve it from the web UI, or have another team member approve it.',
            );
        }

        // Agent-raised proposals carry actor_agent_id and NO actor_user_id —
        // ToolCallGovernor is the one gate that does not pass a user — so the
        // identity check above can never match them, leaving the exact case
        // this guard exists for wide open. Such a proposal needs a human
        // decision, and over MCP the caller IS the agent.
        if ($proposal->actor_agent_id !== null) {
            return $this->permissionDeniedError(
                'This proposal was raised by an agent and must be approved by a human from the web UI, not over MCP.',
            );
        }

        app(ApproveActionProposalAction::class)->execute($proposal, $user, $validated['reason'] ?? null);

        return Response::text(json_encode([
            'success' => true,
            'proposal_id' => $proposal->id,
            'status' => 'approved',
            'note' => 'v1: caller must re-invoke the underlying action; auto-execute is deferred to Sprint 3b.',
        ]));
    }
}
