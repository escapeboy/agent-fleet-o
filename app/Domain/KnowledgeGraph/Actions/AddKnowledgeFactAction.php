<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Enums\EntityType;
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
        private readonly NormalizeKnowledgeInputAction $normalize,
    ) {}

    /**
     * Directly add a structured knowledge fact (no LLM extraction needed).
     * Input is normalized via LLM to prevent entity duplication and relation inconsistency.
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
        bool $skipNormalization = false,
    ): KgEdge {
        $validAt ??= now();

        // LLM-assisted normalization: dedup entities, canonicalize relations, validate fact
        if (! $skipNormalization) {
            $normalized = $this->normalize->execute(
                teamId: $teamId,
                sourceName: $sourceName,
                sourceType: $sourceType,
                relationType: $relationType,
                targetName: $targetName,
                targetType: $targetType,
                fact: $fact,
            );

            $sourceName = $normalized['source_name'];
            $sourceType = $normalized['source_type'];
            $targetName = $normalized['target_name'];
            $targetType = $normalized['target_type'];
            $relationType = $normalized['relation_type'];
            $fact = $normalized['fact'];

            // If normalization flagged the fact as invalid, log it but still store
            // (we don't reject — the user's intent matters more)
            if (! ($normalized['validation']['valid'] ?? true)) {
                Log::info('AddKnowledgeFactAction: Fact flagged by validation', [
                    'reason' => $normalized['validation']['reason'],
                    'fact' => $fact,
                ]);
                $attributes['validation_warning'] = $normalized['validation']['reason'];
            }
        }

        $relationType = mb_substr(Str::snake(strtolower(trim($relationType))), 0, 80);

        // Resolve entities using normalized names (never trust LLM-returned IDs directly)
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

        // Update mention counts on matched entities
        $sourceEntity->increment('mention_count');
        $sourceEntity->update(['last_seen_at' => now()]);
        $targetEntity->increment('mention_count');
        $targetEntity->update(['last_seen_at' => now()]);

        Log::info('AddKnowledgeFactAction: Fact added', [
            'team_id' => $teamId,
            'relation_type' => $relationType,
            'fact' => $fact,
        ]);

        return $edge;
    }

    private function resolveEntity(string $teamId, string $name, string $type): Entity
    {
        $type = EntityType::fromStringOrDefault($type)->value;
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
                ->using(config('memory.embedding_provider', 'openai'), $model)
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
