<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Actions\RejectAction;
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
class ApprovalRejectTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'approval_reject';

    protected string $description = 'Reject a pending approval request. Triggers experiment re-planning or kill if max rejection cycles exceeded.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->string()
                ->description('The approval request UUID')
                ->required(),
            'reason' => $schema->string()
                ->description('Reason for rejection')
                ->required(),
            'notes' => $schema->string()
                ->description('Optional reviewer notes'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'approval_id' => 'required|string',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $approval = ApprovalRequest::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['approval_id']);

        if (! $approval) {
            return $this->notFoundError('approval request');
        }

        try {
            app(RejectAction::class)->execute(
                $approval,
                auth()->id(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'approval_id' => $approval->id,
                'status' => 'rejected',
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
