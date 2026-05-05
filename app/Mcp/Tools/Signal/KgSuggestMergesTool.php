<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Actions\DetectDuplicateEntitiesAction;
use App\Domain\Signal\Models\Entity;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class KgSuggestMergesTool extends Tool
{
    protected string $name = 'kg_suggest_merges';

    protected string $description = 'Suggests near-duplicate entity pairs in the knowledge graph that could be merged to reduce fragmentation. Uses string similarity to find candidates of the same entity type.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'threshold' => $schema->number()
                ->description('Similarity threshold between 0.5 and 1.0 (default: 0.85)')
                ->default(0.85),
            'limit' => $schema->integer()
                ->description('Maximum number of candidates to return (default: 20, max: 50)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'threshold' => 'nullable|numeric|min:0.5|max:1.0',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $teamId = app('mcp.team_id');
        $threshold = (float) ($validated['threshold'] ?? 0.85);
        $limit = min((int) ($validated['limit'] ?? 20), 50);

        /** @var DetectDuplicateEntitiesAction $action */
        $action = app(DetectDuplicateEntitiesAction::class);
        $candidates = $action->execute($teamId, $threshold);
        $candidates = array_slice($candidates, 0, $limit);

        // Enrich with entity details
        $entityIds = array_unique(array_merge(
            array_column($candidates, 'canonical_id'),
            array_column($candidates, 'duplicate_id'),
        ));

        $entities = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('id', $entityIds)
            ->get()
            ->keyBy('id');

        $enriched = array_map(function (array $c) use ($entities): array {
            $canonical = $entities->get($c['canonical_id']);
            $duplicate = $entities->get($c['duplicate_id']);

            return [
                'canonical_id' => $c['canonical_id'],
                'canonical_name' => $canonical?->name,
                'canonical_type' => $canonical?->type,
                'canonical_mentions' => $canonical?->mention_count,
                'duplicate_id' => $c['duplicate_id'],
                'duplicate_name' => $duplicate?->name,
                'duplicate_type' => $duplicate?->type,
                'duplicate_mentions' => $duplicate?->mention_count,
                'confidence' => $c['confidence'],
                'reason' => $c['reason'],
            ];
        }, $candidates);

        return Response::text(json_encode([
            'candidates' => array_values($enriched),
            'count' => count($enriched),
            'threshold' => $threshold,
        ]));
    }
}
