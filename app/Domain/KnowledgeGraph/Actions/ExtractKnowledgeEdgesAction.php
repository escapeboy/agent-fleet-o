<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Enums\EntityType;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Signal\Models\Entity;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;

class ExtractKnowledgeEdgesAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly DetectContradictionAction $detectContradiction,
    ) {}

    /**
     * Extract entity relationship edges from a signal and store them as temporal facts.
     */
    public function execute(Signal $signal): void
    {
        $teamId = $signal->team_id;

        // Get entities already extracted for this signal
        $entities = $signal->entities()->get();
        if ($entities->count() < 2) {
            return; // Need at least 2 entities to form a relationship
        }

        $text = $this->buildTextFromSignal($signal);
        if (mb_strlen($text) < 20) {
            return;
        }

        $entityList = $entities->map(fn (Entity $e) => [
            'name' => $e->name,
            'type' => $e->type,
        ])->values()->toArray();

        $edges = $this->extractEdgesWithLlm($teamId, $text, $entityList);
        if (empty($edges)) {
            return;
        }

        foreach ($edges as $edge) {
            try {
                $this->storeEdge($teamId, $edge, $signal);
            } catch (\Throwable $e) {
                Log::warning('ExtractKnowledgeEdgesAction: Failed to store edge', [
                    'signal_id' => $signal->id,
                    'edge' => $edge,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<int, array{source_entity: string, source_type: string, relation: string,
     *                          target_entity: string, target_type: string, fact: string,
     *                          valid_at: string|null, confidence: float}>
     */
    private function extractEdgesWithLlm(string $teamId, string $text, array $entities): array
    {
        $entityJson = json_encode($entities, JSON_UNESCAPED_UNICODE);

        $entityTypes = implode(', ', EntityType::values());

        $request = new AiRequestDTO(
            provider: config('llm_providers.default_provider', 'anthropic'),
            model: config('llm_providers.default_model', 'claude-haiku-4-5-20251001'),
            systemPrompt: "Extract relationships between named entities from the text. Return ONLY a valid JSON array (no markdown) of objects with: source_entity (string), source_type (one of: {$entityTypes}), relation (snake_case verb like works_at, has_price, has_status, acquired_by, founded_by, located_in, has_title, has_funding, supports, uses, depends_on), target_entity (string), target_type (same enum), fact (human-readable sentence describing the relationship), valid_at (ISO date if explicitly stated in the text, else null), confidence (0.0–1.0). Choose specific entity types over 'topic' when possible (e.g. use 'technology' for frameworks/languages, 'event' for conferences/releases, 'organization' for non-profit entities). Only extract relationships that are clearly stated. Maximum 10 edges.",
            userPrompt: "Entities found: {$entityJson}\n\nText:\n".mb_substr($text, 0, 6000),
            maxTokens: 1024,
            teamId: $teamId,
            purpose: 'kg_edge_extraction',
            temperature: 0.2,
        );

        try {
            $response = $this->gateway->complete($request);
            $content = trim($response->content ?? '');

            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```\w*\n?/', '', $content);
                $content = preg_replace('/\n?```$/', '', $content);
            }

            $decoded = json_decode(trim($content), true);
            if (! is_array($decoded)) {
                return [];
            }

            // Handle {edges: [...]} wrapper
            if (isset($decoded['edges']) && is_array($decoded['edges'])) {
                $decoded = $decoded['edges'];
            }

            // Filter by confidence and required fields
            return array_filter($decoded, fn ($e) => isset($e['source_entity'], $e['relation'], $e['target_entity'], $e['fact'])
                && ((float) ($e['confidence'] ?? 1.0)) >= 0.7,
            );
        } catch (\Throwable $e) {
            Log::warning('ExtractKnowledgeEdgesAction: LLM extraction failed', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function storeEdge(string $teamId, array $edge, Signal $signal): void
    {
        $sourceType = EntityType::fromStringOrDefault($edge['source_type'] ?? 'topic')->value;
        $targetType = EntityType::fromStringOrDefault($edge['target_type'] ?? 'topic')->value;

        // Resolve or create entity nodes
        $sourceEntity = $this->resolveEntity($teamId, $edge['source_entity'], $sourceType);
        $targetEntity = $this->resolveEntity($teamId, $edge['target_entity'], $targetType);

        $relationType = Str::snake(strtolower(trim($edge['relation'])));
        $relationType = mb_substr($relationType, 0, 80);

        $validAt = null;
        if (! empty($edge['valid_at'])) {
            try {
                $validAt = Carbon::parse($edge['valid_at']);
            } catch (\Throwable) {
                $validAt = $signal->received_at ?? now();
            }
        } else {
            $validAt = $signal->received_at ?? now();
        }

        // Generate embedding for the fact
        $factEmbedding = $this->generateEmbedding($edge['fact']);

        // Detect and invalidate contradicting facts
        if ($factEmbedding) {
            $this->detectContradiction->execute(
                teamId: $teamId,
                sourceEntityId: $sourceEntity->id,
                relationType: $relationType,
                newFact: $edge['fact'],
                newFactEmbeddingStr: $factEmbedding,
                validAt: $validAt,
            );
        }

        KgEdge::create([
            'team_id' => $teamId,
            'source_entity_id' => $sourceEntity->id,
            'target_entity_id' => $targetEntity->id,
            'relation_type' => $relationType,
            'fact' => $edge['fact'],
            'fact_embedding' => $factEmbedding ? $this->embeddingToArray($factEmbedding) : null,
            'valid_at' => $validAt,
            'invalid_at' => null,
            'episode_id' => $signal->id,
            'attributes' => ['confidence' => $edge['confidence'] ?? 1.0],
        ]);
    }

    private function resolveEntity(string $teamId, string $name, string $type): Entity
    {
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
            Log::warning('ExtractKnowledgeEdgesAction: Embedding generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function embeddingToArray(string $embeddingStr): array
    {
        $stripped = trim($embeddingStr, '[]');

        return array_map('floatval', explode(',', $stripped));
    }

    private function buildTextFromSignal(Signal $signal): string
    {
        $payload = $signal->payload ?? [];
        $parts = array_filter([
            $payload['title'] ?? $payload['subject'] ?? $payload['summary'] ?? '',
            $payload['description'] ?? $payload['body'] ?? $payload['content'] ?? $payload['text'] ?? '',
        ]);

        return implode("\n\n", $parts);
    }
}
