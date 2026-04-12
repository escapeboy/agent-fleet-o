<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Actions\InvalidateKgFactAction;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

/**
 * MCP tool for invalidating (soft-deleting) a knowledge graph fact by UUID.
 *
 * Sets invalid_at = now(). The fact is retained for historical queries
 * but excluded from all future searches and entity fact lookups.
 */
#[IsDestructive]
#[AssistantTool('read')]
class KgInvalidateFactTool extends Tool
{
    protected string $name = 'kg_invalidate_fact';

    protected string $description = 'Invalidate (soft-delete) a knowledge graph fact by its UUID. Sets invalid_at = now(). The fact is retained for history but excluded from future searches.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'fact_id' => $schema->string()
                ->description('KgEdge UUID to invalidate')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if ($teamId === null) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate(['fact_id' => 'required|string']);

        $edge = KgEdge::withoutGlobalScopes()
            ->where('id', $validated['fact_id'])
            ->where('team_id', $teamId)
            ->first();

        if (! $edge) {
            return Response::error('KG fact not found.');
        }

        if ($edge->invalid_at !== null) {
            return Response::text(json_encode([
                'message' => 'Already invalidated.',
                'fact_id' => $edge->id,
            ]));
        }

        (new InvalidateKgFactAction)->execute($edge);

        return Response::text(json_encode([
            'success' => true,
            'fact_id' => $edge->id,
            'message' => 'Fact invalidated.',
        ]));
    }
}
