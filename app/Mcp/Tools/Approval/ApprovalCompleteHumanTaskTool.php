<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Actions\CompleteHumanTaskAction;
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
class ApprovalCompleteHumanTaskTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'approval_complete_human_task';

    protected string $description = 'Complete a pending human task by submitting form data. The task must be a human_task type approval request with a form_schema. Resumes the workflow after completion.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->string()
                ->description('The human task approval request UUID')
                ->required(),
            'form_response' => $schema->object()
                ->description('The form response data as key-value pairs matching the form_schema fields')
                ->required(),
            'notes' => $schema->string()
                ->description('Optional reviewer notes'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'approval_id' => 'required|string',
            'form_response' => 'required|array',
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

        if (! $approval->isHumanTask()) {
            return $this->failedPreconditionError('This approval request is not a human task.');
        }

        try {
            app(CompleteHumanTaskAction::class)->execute(
                approvalRequest: $approval,
                formResponse: $validated['form_response'],
                reviewerId: auth()->id(),
                notes: $validated['notes'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'approval_id' => $approval->id,
                'status' => 'completed',
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
