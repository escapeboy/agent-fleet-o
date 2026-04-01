<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListPendingApprovalsTool implements Tool
{
    public function name(): string
    {
        return 'list_pending_approvals';
    }

    public function description(): string
    {
        return 'List pending approval requests that need review';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $approvals = ApprovalRequest::where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit($request->get('limit', 10))
            ->get(['id', 'type', 'payload', 'created_at']);

        return json_encode([
            'count' => $approvals->count(),
            'approvals' => $approvals->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'created' => $a->created_at->diffForHumans(),
            ])->toArray(),
        ]);
    }
}
