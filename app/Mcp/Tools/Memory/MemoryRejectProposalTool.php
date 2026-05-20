<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\RejectMemoryProposalAction;
use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool to reject a Proposed-tier memory.
 *
 * Stamps proposal_status='rejected' plus a reason — keeps the row for audit
 * but excludes it from retrieval and the proposals list.
 */
#[IsDestructive]
#[AssistantTool('write')]
class MemoryRejectProposalTool extends Tool
{
    protected string $name = 'memory_reject_proposal';

    protected string $description = 'Reject a proposed memory. Records the reason and stops it from surfacing in retrieval. '
        .'Use this when a proposal is noisy, duplicated, or wrong. Idempotent on already-decided memories.';

    public function __construct(private readonly RejectMemoryProposalAction $reject) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_id' => $schema->string()
                ->description('UUID of the proposed memory to reject')
                ->required(),
            'reason' => $schema->string()
                ->description('Why this proposal is being rejected (max 1000 chars)')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $validated = $request->validate([
            'memory_id' => 'required|uuid|exists:memories,id',
            'reason' => 'required|string|max:1000',
        ]);

        $memory = Memory::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->findOrFail($validated['memory_id']);

        $result = $this->reject->execute(
            $memory,
            $validated['reason'],
            'mcp:'.(auth()->user()?->email ?? 'anonymous'),
        );

        return Response::text(json_encode([
            'success' => true,
            'memory_id' => $memory->id,
            'rejected' => $result['rejected'],
            'already' => $result['already'],
        ]));
    }
}
