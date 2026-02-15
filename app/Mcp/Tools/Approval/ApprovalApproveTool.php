<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ApprovalApproveTool extends Tool
{
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

        $approval = ApprovalRequest::find($validated['approval_id']);

        if (! $approval) {
            return Response::error('Approval request not found.');
        }

        try {
            app(ApproveAction::class)->execute(
                $approval,
                auth()->id(),
                $validated['notes'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'approval_id' => $approval->id,
                'status' => 'approved',
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
