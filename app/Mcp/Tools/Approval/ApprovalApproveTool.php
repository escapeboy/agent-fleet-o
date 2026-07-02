<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ApprovalApproveTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'approval_approve';

    protected string $description = 'Approve a pending approval request. Triggers experiment transition to approved and executing.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->string()
                ->description('The approval request UUID')
                ->required(),
            'notes' => $schema->string()
                ->description('Optional reviewer notes'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'approval_id' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $approval = ApprovalRequest::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['approval_id']);

        if (! $approval) {
            return $this->notFoundError('approval request');
        }

        try {
            app(ApproveAction::class)->execute(
                $approval,
                auth()->id(),
                $validated['notes'] ?? null,
            );

            $approval->refresh();

            return Response::text(json_encode([
                'success' => true,
                'approval_id' => $approval->id,
                'status' => $approval->status->value,
                'approvals_recorded' => $approval->approveVoteCount(),
                'approvals_required' => (int) $approval->required_approvals,
                'quorum_reached' => $approval->quorumReached(),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
