<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ApprovalListTool extends Tool
{
    protected string $name = 'approval_list';

    protected string $description = 'List approval requests with optional status filter. Defaults to pending. Returns id, type, payload summary, created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: pending, approved, rejected, expired (default: pending)')
                ->enum(['pending', 'approved', 'rejected', 'expired'])
                ->default('pending'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = ApprovalRequest::query()->orderByDesc('created_at');

        $status = $request->get('status', 'pending');
        $query->where('status', $status);

        $limit = min((int) ($request->get('limit', 10)), 100);

        $approvals = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $approvals->count(),
            'approvals' => $approvals->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->experiment_id ? 'experiment' : ($a->outbound_proposal_id ? 'outbound' : 'unknown'),
                'payload' => mb_substr(json_encode($a->context ?? []), 0, 200),
                'created_at' => $a->created_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
