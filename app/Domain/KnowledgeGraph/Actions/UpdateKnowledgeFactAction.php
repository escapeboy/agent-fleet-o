<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Enums\EntityType;
use App\Domain\KnowledgeGraph\Models\Entity;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Prism\Prism\Prism;

class UpdateKnowledgeFactAction
{
    public function execute(
        KgEdge $edge,
        string $sourceName,
        string $sourceType,
        string $relationType,
        string $targetName,
        string $targetType,
        string $fact,
    ): KgEdge {
        $relationType = mb_substr(Str::snake(strtolower(trim($relationType))), 0, 80);

        $sourceEntity = $this->resolveEntity($edge->team_id, $sourceName, $sourceType);
        $targetEntity = $this->resolveEntity($edge->team_id, $targetName, $targetType);

        $data = [
            'source_entity_id' => $sourceEntity->id,
            'target_entity_id' => $targetEntity->id,
            'relation_type' => $relationType,
            'fact' => $fact,
        ];

        // Re-generate embedding if fact text changed
        if ($edge->fact !== $fact) {
            $factEmbedding = $this->generateEmbedding($fact);
            if ($factEmbedding && Schema::hasColumn('kg_edges', 'fact_embedding')) {
                $data['fact_embedding'] = $this->embeddingToArray($factEmbedding);
            }
        }

        $edge->update($data);

        Log::info('UpdateKnowledgeFactAction: Fact updated', [
            'edge_id' => $edge->id,
            'team_id' => $edge->team_id,
            'fact' => $fact,
        ]);

        return $edge->refresh();
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
            Log::warning('UpdateKnowledgeFactAction: Embedding generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function embeddingToArray(string $embeddingStr): array
    {
        $stripped = trim($embeddingStr, '[]');

        return array_map('floatval', explode(',', $stripped));
    }
}
