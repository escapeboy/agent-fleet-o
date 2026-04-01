<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ApproveRequestTool implements Tool
{
    public function name(): string
    {
        return 'approve_request';
    }

    public function description(): string
    {
        return 'Approve a pending approval request';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->string()->required()->description('The approval request UUID'),
            'notes' => $schema->string()->description('Optional approval notes'),
        ];
    }

    public function handle(Request $request): string
    {
        $approval = ApprovalRequest::find($request->get('approval_id'));
        if (! $approval) {
            return json_encode(['error' => 'Approval request not found']);
        }

        try {
            app(ApproveAction::class)->execute($approval, auth()->id(), $request->get('notes'));

            return json_encode(['success' => true, 'message' => 'Request approved.']);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
