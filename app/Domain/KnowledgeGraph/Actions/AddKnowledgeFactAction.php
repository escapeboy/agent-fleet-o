<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Signal\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;

class AddKnowledgeFactAction
{
    public function __construct(
        private readonly DetectContradictionAction $detectContradiction,
    ) {}

    /**
     * Directly add a structured knowledge fact (no LLM extraction needed).
     * Used for project/task state changes, experiment completions, etc.
     */
    public function execute(
        string $teamId,
        string $sourceName,
        string $sourceType,
        string $relationType,
        string $targetName,
        string $targetType,
        string $fact,
        ?Carbon $validAt = null,
        ?string $episodeId = null,
        array $attributes = [],
    ): KgEdge {
        $validAt ??= now();
        $relationType = mb_substr(Str::snake(strtolower(trim($relationType))), 0, 80);

        $sourceEntity = $this->resolveEntity($teamId, $sourceName, $sourceType);
        $targetEntity = $this->resolveEntity($teamId, $targetName, $targetType);

        $factEmbedding = $this->generateEmbedding($fact);

        // Detect and invalidate contradicting facts
        if ($factEmbedding) {
            $this->detectContradiction->execute(
                teamId: $teamId,
                sourceEntityId: $sourceEntity->id,
                relationType: $relationType,
                newFact: $fact,
                newFactEmbeddingStr: $factEmbedding,
                validAt: $validAt,
            );
        }

        $data = [
            'team_id' => $teamId,
            'source_entity_id' => $sourceEntity->id,
            'target_entity_id' => $targetEntity->id,
            'relation_type' => $relationType,
            'fact' => $fact,
            'valid_at' => $validAt,
            'invalid_at' => null,
            'episode_id' => $episodeId,
            'attributes' => $attributes,
        ];

        // Only include fact_embedding when pgvector column exists
        if ($factEmbedding && Schema::hasColumn('kg_edges', 'fact_embedding')) {
            $data['fact_embedding'] = $this->embeddingToArray($factEmbedding);
        }

        $edge = KgEdge::create($data);

        Log::info('AddKnowledgeFactAction: Fact added', [
            'team_id' => $teamId,
            'relation_type' => $relationType,
            'fact' => $fact,
        ]);

        return $edge;
    }

    private function resolveEntity(string $teamId, string $name, string $type): Entity
    {
        $validTypes = ['person', 'company', 'location', 'date', 'product', 'topic'];
        $type = in_array($type, $validTypes, true) ? $type : 'topic';
        $canonicalName = Str::lower(Str::ascii(trim($name)));

        return Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('type', $type)
            ->where('canonical_name', $canonicalName)
            ->firstOr(fn () => Entity::create([
                'team_id' => $teamId,
                'type' => $type,
                'name' => trim($name),
                'canonical_name' => $canonicalName,
                'metadata' => [],
                'mention_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]));
    }

    private function generateEmbedding(string $text): ?string
    {
        try {
            $model = config('memory.embedding_model', 'text-embedding-3-small');

            $response = Prism::embeddings()
                ->using('openai', $model)
                ->fromInput($text)
                ->asEmbeddings();

            $vector = $response->embeddings[0]->embedding;

            return '['.implode(',', $vector).']';
        } catch (\Throwable $e) {
            Log::warning('AddKnowledgeFactAction: Embedding generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function embeddingToArray(string $embeddingStr): array
    {
        $stripped = trim($embeddingStr, '[]');

        return array_map('floatval', explode(',', $stripped));
    }
}
