<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RejectRequestTool implements Tool
{
    public function name(): string
    {
        return 'reject_request';
    }

    public function description(): string
    {
        return 'Reject a pending approval request';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->string()->required()->description('The approval request UUID'),
            'reason' => $schema->string()->required()->description('Reason for rejection'),
            'notes' => $schema->string()->description('Optional rejection notes'),
        ];
    }

    public function handle(Request $request): string
    {
        $approval = ApprovalRequest::find($request->get('approval_id'));
        if (! $approval) {
            return json_encode(['error' => 'Approval request not found']);
        }

        try {
            app(RejectAction::class)->execute($approval, auth()->id(), $request->get('reason'), $request->get('notes'));

            return json_encode(['success' => true, 'message' => 'Request rejected.']);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
