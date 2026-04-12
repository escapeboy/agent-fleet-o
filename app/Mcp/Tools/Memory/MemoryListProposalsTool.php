<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool to list unreviewed memories in the `proposed` tier.
 *
 * Returns memories that agents have proposed but a human has not yet
 * promoted or discarded. Use memory_promote to approve them.
 */
#[IsReadOnly]
#[AssistantTool('read')]
class MemoryListProposalsTool extends Tool
{
    protected string $name = 'memory_list_proposals';

    protected string $description = 'List unreviewed memories in the proposed tier. '
        .'These are agent-extracted facts awaiting human review. '
        .'Use memory_promote to promote a proposal to canonical/facts/decisions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Filter by agent UUID (optional)'),
            'proposed_by' => $schema->string()
                ->description('Filter by proposer identifier, e.g. "agent:{uuid}" (optional)'),
            'limit' => $schema->integer()
                ->description('Max number of results to return. Default: 20')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'nullable|uuid',
            'proposed_by' => 'nullable|string|max:200',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Memory::query()
            ->where('tier', MemoryTier::Proposed->value)
            ->orderBy('created_at', 'desc');

        if (! empty($validated['agent_id'])) {
            $query->where('agent_id', $validated['agent_id']);
        }

        if (! empty($validated['proposed_by'])) {
            $query->where('proposed_by', $validated['proposed_by']);
        }

        $limit = $validated['limit'] ?? 20;
        $proposals = $query->limit($limit)->get();

        $items = $proposals->map(fn (Memory $m) => [
            'id' => $m->id,
            'content' => mb_substr($m->content, 0, 300),
            'proposed_by' => $m->proposed_by,
            'agent_id' => $m->agent_id,
            'project_id' => $m->project_id,
            'confidence' => $m->confidence,
            'tags' => $m->tags ?? [],
            'created_at' => $m->created_at?->toIso8601String(),
        ])->values()->toArray();

        return Response::text(json_encode([
            'total' => $proposals->count(),
            'proposals' => $items,
        ]));
    }
}
