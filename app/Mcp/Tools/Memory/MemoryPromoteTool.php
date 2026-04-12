<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool to promote a memory to a higher curation tier.
 *
 * Only admin/owner roles should call this — it signals that a memory
 * has been reviewed and is now considered trusted knowledge.
 * Promoted memories receive a +0.10 retrieval score boost.
 */
#[IsDestructive]
#[AssistantTool('write')]
class MemoryPromoteTool extends Tool
{
    protected string $name = 'memory_promote';

    protected string $description = 'Promote a memory to a higher trust tier (canonical, facts, decisions, failures, successes). '
        .'Promoted memories receive a retrieval score boost and are surfaced preferentially. '
        .'Requires admin/owner role.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_id' => $schema->string()
                ->description('UUID of the memory to promote')
                ->required(),
            'target_tier' => $schema->string()
                ->description('Target tier: canonical | facts | decisions | failures | successes')
                ->enum(['canonical', 'facts', 'decisions', 'failures', 'successes'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $validated = $request->validate([
            'memory_id' => 'required|uuid|exists:memories,id',
            'target_tier' => 'required|string|in:canonical,facts,decisions,failures,successes',
        ]);

        $tier = MemoryTier::from($validated['target_tier']);

        $memory = Memory::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->findOrFail($validated['memory_id']);
        $previousTier = $memory->tier?->value ?? 'working';

        $memory->update(['tier' => $tier->value]);

        return Response::text(json_encode([
            'success' => true,
            'memory_id' => $memory->id,
            'previous_tier' => $previousTier,
            'new_tier' => $tier->value,
            'is_curated' => $tier->isCurated(),
            'retrieval_boost' => $tier->retrievalBoost(),
        ]));
    }
}
