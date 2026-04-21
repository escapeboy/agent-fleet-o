<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Enums\EntityType;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Support\LlmDefaults;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NormalizeKnowledgeInputAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Normalize knowledge graph input in a single LLM call:
     * - Resolve entity names to existing entities (fuzzy dedup)
     * - Suggest best entity types from expanded vocabulary
     * - Canonicalize relation type to match existing types
     * - Validate fact coherence
     *
     * @return array{
     *     source_name: string, source_type: string,
     *     target_name: string, target_type: string,
     *     relation_type: string, fact: string,
     *     validation: array{valid: bool, reason: string|null},
     *     source_matched_entity_id: string|null,
     *     target_matched_entity_id: string|null,
     * }
     */
    public function execute(
        string $teamId,
        string $sourceName,
        string $sourceType,
        string $relationType,
        string $targetName,
        string $targetType,
        string $fact,
    ): array {
        // Gather context: similar entities + existing relation types
        $sourceNameNorm = Str::lower(Str::ascii(trim($sourceName)));
        $targetNameNorm = Str::lower(Str::ascii(trim($targetName)));

        $similarEntities = $this->findSimilarEntities($teamId, $sourceNameNorm, $targetNameNorm);
        $existingRelationTypes = $this->getExistingRelationTypes($teamId);

        $entityTypes = implode(', ', EntityType::values());

        $existingEntitiesJson = json_encode($similarEntities, JSON_UNESCAPED_UNICODE);
        $existingRelationsJson = json_encode($existingRelationTypes, JSON_UNESCAPED_UNICODE);

        $request = new AiRequestDTO(
            provider: LlmDefaults::provider(),
            model: LlmDefaults::model(),
            systemPrompt: <<<PROMPT
You are a knowledge graph data quality assistant. Given raw input for a knowledge graph fact, normalize it for consistency.

Your tasks:
1. ENTITY DEDUPLICATION: Check if the source/target entities match any existing entities (considering spelling variations, abbreviations, version formats like "v4.5" vs "4.5", case differences). If a match exists, use the existing entity's exact name and ID.
2. ENTITY TYPE SELECTION: Choose the best entity type from: {$entityTypes}. Prefer specific types over "topic".
3. RELATION TYPE NORMALIZATION: If the relation type is semantically equivalent to an existing one, use the existing one. Otherwise normalize to snake_case.
4. FACT VALIDATION: Check that the fact text is coherent with the source → relation → target structure. Flag if nonsensical.

Return ONLY valid JSON (no markdown):
{
  "source_name": "canonical name",
  "source_type": "best type",
  "source_matched_entity_id": "existing entity UUID or null",
  "target_name": "canonical name",
  "target_type": "best type",
  "target_matched_entity_id": "existing entity UUID or null",
  "relation_type": "canonical_snake_case",
  "fact": "cleaned fact text",
  "valid": true/false,
  "validation_reason": "reason if invalid, null if valid"
}
PROMPT,
            userPrompt: <<<INPUT
Raw input:
- Source: "{$sourceName}" (type: {$sourceType})
- Relation: "{$relationType}"
- Target: "{$targetName}" (type: {$targetType})
- Fact: "{$fact}"

Existing similar entities in this team's graph:
{$existingEntitiesJson}

Existing relation types in this team's graph:
{$existingRelationsJson}
INPUT,
            maxTokens: 512,
            teamId: $teamId,
            purpose: 'kg_normalize_input',
            temperature: 0.1,
        );

        try {
            $response = $this->gateway->complete($request);
            $content = trim($response->content ?? '');

            // Strip markdown fences
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```\w*\n?/', '', $content);
                $content = preg_replace('/\n?```$/', '', $content);
            }

            $normalized = json_decode(trim($content), true);

            if (! is_array($normalized) || ! isset($normalized['source_name'])) {
                return $this->fallbackNormalization($sourceName, $sourceType, $relationType, $targetName, $targetType, $fact);
            }

            // Validate entity types against enum
            $normalized['source_type'] = EntityType::fromStringOrDefault($normalized['source_type'] ?? $sourceType)->value;
            $normalized['target_type'] = EntityType::fromStringOrDefault($normalized['target_type'] ?? $targetType)->value;

            // Ensure relation type is snake_case and within bounds
            $normalized['relation_type'] = mb_substr(
                Str::snake(strtolower(trim($normalized['relation_type'] ?? $relationType))),
                0,
                80,
            );

            return [
                'source_name' => trim($normalized['source_name']),
                'source_type' => $normalized['source_type'],
                'target_name' => trim($normalized['target_name']),
                'target_type' => $normalized['target_type'],
                'relation_type' => $normalized['relation_type'],
                'fact' => trim($normalized['fact'] ?? $fact),
                'validation' => [
                    'valid' => $normalized['valid'] ?? true,
                    'reason' => $normalized['validation_reason'] ?? null,
                ],
                'source_matched_entity_id' => $normalized['source_matched_entity_id'] ?? null,
                'target_matched_entity_id' => $normalized['target_matched_entity_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('NormalizeKnowledgeInputAction: LLM normalization failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackNormalization($sourceName, $sourceType, $relationType, $targetName, $targetType, $fact);
        }
    }

    /**
     * Find existing entities similar to the given names (ILIKE prefix/substring match).
     */
    private function findSimilarEntities(string $teamId, string $sourceNameNorm, string $targetNameNorm): array
    {
        $likeOp = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        // Escape LIKE metacharacters to prevent wildcard injection
        $srcEsc = str_replace(['%', '_'], ['\%', '\_'], $sourceNameNorm);
        $tgtEsc = str_replace(['%', '_'], ['\%', '\_'], $targetNameNorm);

        // Search for entities that could match source or target
        $candidates = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(function ($q) use ($srcEsc, $tgtEsc, $likeOp) {
                $q->where('canonical_name', $likeOp, "{$srcEsc}%")
                    ->orWhere('canonical_name', $likeOp, "%{$srcEsc}%")
                    ->orWhere('canonical_name', $likeOp, "{$tgtEsc}%")
                    ->orWhere('canonical_name', $likeOp, "%{$tgtEsc}%");
            })
            ->orderByDesc('mention_count')
            ->limit(20)
            ->get(['id', 'name', 'canonical_name', 'type', 'mention_count']);

        return $candidates->map(fn (Entity $e) => [
            'id' => $e->id,
            'name' => $e->name,
            'type' => $e->type,
            'mentions' => $e->mention_count,
        ])->values()->toArray();
    }

    /**
     * Get distinct relation types already used by this team.
     */
    private function getExistingRelationTypes(string $teamId): array
    {
        return KgEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNull('invalid_at')
            ->distinct()
            ->pluck('relation_type')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Fallback when LLM is unavailable — apply basic normalization rules.
     */
    private function fallbackNormalization(
        string $sourceName,
        string $sourceType,
        string $relationType,
        string $targetName,
        string $targetType,
        string $fact,
    ): array {
        return [
            'source_name' => trim($sourceName),
            'source_type' => EntityType::fromStringOrDefault($sourceType)->value,
            'target_name' => trim($targetName),
            'target_type' => EntityType::fromStringOrDefault($targetType)->value,
            'relation_type' => mb_substr(Str::snake(strtolower(trim($relationType))), 0, 80),
            'fact' => trim($fact),
            'validation' => ['valid' => true, 'reason' => null],
            'source_matched_entity_id' => null,
            'target_matched_entity_id' => null,
        ];
    }
}
