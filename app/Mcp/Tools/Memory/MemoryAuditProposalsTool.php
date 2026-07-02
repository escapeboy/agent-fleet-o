<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\AuditMemoryProposalsAction;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool that triggers the heuristic auditor over pending memory proposals
 * for the current team. Mirrors the scheduled `memory:audit-proposals` command.
 */
#[IsDestructive]
#[AssistantTool('write')]
class MemoryAuditProposalsTool extends Tool
{
    protected string $name = 'memory_audit_proposals';

    protected string $description = 'Run the heuristic memory-proposal auditor for the current team. '
        .'Auto-approves high-confidence proposals, auto-rejects short/low-confidence ones, leaves the rest pending.';

    public function __construct(private readonly AuditMemoryProposalsAction $auditor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max proposals to inspect this run. Default: 200, max: 1000.')
                ->default(200),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $result = $this->auditor->execute(
            teamId: $teamId,
            limit: $validated['limit'] ?? 200,
        );

        return Response::text(json_encode([
            'success' => true,
            'approved' => $result['approved'],
            'rejected' => $result['rejected'],
            'queued' => $result['queued'],
        ]));
    }
}
