<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryTier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool that stores a memory in the `proposed` tier.
 *
 * Agents use this to surface candidate memories for human review
 * rather than writing directly to the working tier.
 * Proposed memories receive a lower retrieval boost until promoted to a curated tier.
 */
class MemoryProposeTool extends Tool
{
    protected string $name = 'memory_propose';

    protected string $description = 'Propose a memory for human review. Stores it in the "proposed" tier. '
        .'A human can later promote it to canonical/facts/decisions via the Memory Browser. '
        .'Use when you want to surface a potentially important fact without immediately trusting it.';

    public function __construct(private readonly StoreMemoryAction $store) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description('The memory text to propose')
                ->required(),
            'agent_id' => $schema->string()
                ->description('Agent UUID to associate with this memory (optional)'),
            'project_id' => $schema->string()
                ->description('Project UUID to associate with this memory (optional)'),
            'tags' => $schema->array()
                ->description('Tags for grouping and filtering')
                ->items($schema->string()),
            'confidence' => $schema->number()
                ->description('Confidence score 0.0–1.0. Default: 0.8')
                ->default(0.8),
            'metadata' => $schema->object()
                ->description('Additional structured metadata (key-value pairs)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $validated = $request->validate([
            'content' => 'required|string',
            'agent_id' => ['nullable', 'uuid', Rule::exists('agents', 'id')->where('team_id', $teamId)],
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('team_id', $teamId)],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'metadata' => 'nullable|array',
        ]);
        $agentId = $validated['agent_id'] ?? null;

        // Identify the proposer from the agent id or the MCP caller
        $proposedBy = $agentId ? "agent:{$agentId}" : 'mcp:manual';

        $memories = $this->store->execute(
            teamId: $teamId,
            agentId: $agentId ?? $teamId, // fall back to team id if no agent
            content: $validated['content'],
            sourceType: 'mcp_proposal',
            projectId: $validated['project_id'] ?? null,
            metadata: $validated['metadata'] ?? [],
            confidence: $validated['confidence'] ?? 0.8,
            tags: $validated['tags'] ?? [],
            tier: MemoryTier::Proposed,
            proposedBy: $proposedBy,
        );

        $stored = count($memories);

        return Response::text(json_encode([
            'success' => $stored > 0,
            'stored' => $stored,
            'tier' => 'proposed',
            'proposed_by' => $proposedBy,
            'memory_ids' => array_map(fn ($m) => $m->id, $memories),
        ]));
    }
}
