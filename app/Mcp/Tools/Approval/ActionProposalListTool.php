<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Models\ActionProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ActionProposalListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'action_proposal_list';

    protected string $description = 'List action proposals (real-world action gate). Returns id, target_type, summary, status, risk_level, created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status (default: pending)')
                ->enum(['pending', 'approved', 'rejected', 'expired'])
                ->default('pending'),
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $status = (string) $request->get('status', 'pending');
        $limit = min((int) $request->get('limit', 20), 100);

        $proposals = ActionProposal::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::text(json_encode([
            'count' => $proposals->count(),
            'proposals' => $proposals->map(fn ($p) => [
                'id' => $p->id,
                'target_type' => $p->target_type,
                'target_id' => $p->target_id,
                'summary' => $p->summary,
                'risk_level' => $p->risk_level,
                'status' => $p->status->value,
                'created_at' => $p->created_at?->toIso8601String(),
                'expires_at' => $p->expires_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
