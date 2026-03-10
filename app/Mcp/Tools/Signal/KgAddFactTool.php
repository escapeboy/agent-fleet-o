<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Actions\AddKnowledgeFactAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool for directly adding a structured fact to the knowledge graph.
 *
 * Allows agents to explicitly record knowledge (e.g. after discovering a competitor
 * changed their price, or confirming a contact's new role). Contradiction detection
 * runs automatically — conflicting current facts will be invalidated.
 */
class KgAddFactTool extends Tool
{
    protected string $name = 'kg_add_fact';

    protected string $description = 'Add a structured fact to the temporal knowledge graph. Contradiction detection runs automatically — if an existing current fact conflicts with the new one, it will be invalidated. Use this when an agent discovers a real-world fact that should be remembered across sessions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'source_entity' => $schema->string()
                ->description('Name of the source entity, e.g. "Alice Chen" or "Acme Corp"')
                ->required(),
            'source_type' => $schema->string()
                ->description('Type of the source entity: person | company | location | product | topic')
                ->enum(['person', 'company', 'location', 'product', 'topic'])
                ->required(),
            'relation_type' => $schema->string()
                ->description('Relationship type in snake_case, e.g. works_at, has_price, has_status, acquired_by, founded_by')
                ->required(),
            'target_entity' => $schema->string()
                ->description('Name of the target entity, e.g. "Beta Corp" or "$79/month"')
                ->required(),
            'target_type' => $schema->string()
                ->description('Type of the target entity: person | company | location | product | topic')
                ->enum(['person', 'company', 'location', 'product', 'topic'])
                ->required(),
            'fact' => $schema->string()
                ->description('Human-readable fact statement, e.g. "Alice Chen is VP Engineering at Beta Corp"')
                ->required(),
            'valid_at' => $schema->string()
                ->description('ISO 8601 datetime when this fact became true (defaults to now if omitted)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'source_entity' => 'required|string|max:255',
            'source_type'   => 'required|string|in:person,company,location,product,topic',
            'relation_type' => 'required|string|max:80',
            'target_entity' => 'required|string|max:255',
            'target_type'   => 'required|string|in:person,company,location,product,topic',
            'fact'          => 'required|string|max:1000',
            'valid_at'      => 'nullable|string',
        ]);

        try {
            $validAt = null;
            if (! empty($validated['valid_at'])) {
                $validAt = \Illuminate\Support\Carbon::parse($validated['valid_at']);
            }

            /** @var AddKnowledgeFactAction $action */
            $action = app(AddKnowledgeFactAction::class);

            $edge = $action->execute(
                teamId: app('mcp.team_id'),
                sourceName: $validated['source_entity'],
                sourceType: $validated['source_type'],
                relationType: $validated['relation_type'],
                targetName: $validated['target_entity'],
                targetType: $validated['target_type'],
                fact: $validated['fact'],
                validAt: $validAt,
            );

            return Response::text(json_encode([
                'success'       => true,
                'edge_id'       => $edge->id,
                'fact'          => $edge->fact,
                'relation_type' => $edge->relation_type,
                'valid_at'      => $edge->valid_at?->toIso8601String(),
                'message'       => 'Fact added to the knowledge graph. Any conflicting current facts have been invalidated.',
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
