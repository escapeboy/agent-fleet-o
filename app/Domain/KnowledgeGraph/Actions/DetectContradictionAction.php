<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetectContradictionAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Find active edges that contradict a new fact and invalidate them.
     *
     * @param  string  $newFactEmbeddingStr  pgvector-formatted string e.g. "[0.1,0.2,...]"
     * @return string[] IDs of invalidated edges
     */
    public function execute(
        string $teamId,
        string $sourceEntityId,
        string $relationType,
        string $newFact,
        string $newFactEmbeddingStr,
        Carbon $validAt,
    ): array {
        // 1. Find active edges with the same source entity and relation type
        // Use DB::transaction() (savepoint when nested) so a failed query does not
        // abort the outer transaction — pgvector column may not exist.
        try {
            $candidates = DB::transaction(fn () => KgEdge::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('source_entity_id', $sourceEntityId)
                ->where('relation_type', $relationType)
                ->whereNull('invalid_at')
                ->whereNotNull('fact_embedding')
                ->get());
        } catch (QueryException $e) {
            // fact_embedding column may not exist (PostgreSQL without pgvector extension)
            Log::debug('DetectContradictionAction: fact_embedding column unavailable', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($candidates->isEmpty()) {
            return [];
        }

        // 2. Filter by cosine similarity > 0.6 in-memory (potential contradictions)
        $newVector = $this->parseEmbedding($newFactEmbeddingStr);
        $similar = $candidates->filter(function (KgEdge $edge) use ($newVector) {
            if (empty($edge->fact_embedding)) {
                return true; // include if no embedding (safer to check)
            }
            $similarity = $this->cosineSimilarity($newVector, $edge->fact_embedding);

            return $similarity > 0.6;
        });

        if ($similar->isEmpty()) {
            return [];
        }

        // 3. LLM: determine which existing facts are contradicted by the new fact
        $toInvalidate = $this->llmContradictionCheck($teamId, $newFact, $similar);

        if (empty($toInvalidate)) {
            return [];
        }

        // 4. Invalidate superseded facts
        KgEdge::withoutGlobalScopes()
            ->whereIn('id', $toInvalidate)
            ->update([
                'invalid_at' => $validAt,
                'expired_at' => now(),
            ]);

        Log::info('DetectContradictionAction: Invalidated edges', [
            'team_id' => $teamId,
            'relation_type' => $relationType,
            'new_fact' => $newFact,
            'invalidated_ids' => $toInvalidate,
        ]);

        return $toInvalidate;
    }

    /**
     * Ask LLM which of the existing facts are contradicted by the new fact.
     *
     * @return string[] Edge IDs to invalidate
     */
    private function llmContradictionCheck(string $teamId, string $newFact, Collection $candidates): array
    {
        $existingFacts = $candidates->map(fn (KgEdge $e) => [
            'id' => $e->id,
            'fact' => $e->fact,
        ])->values()->toArray();

        $existingJson = json_encode($existingFacts, JSON_UNESCAPED_UNICODE);

        $request = new AiRequestDTO(
            provider: config('llm_providers.default_provider', 'anthropic'),
            model: config('llm_providers.default_model', 'claude-haiku-4-5-20251001'),
            systemPrompt: 'You are a knowledge graph contradiction detector. Given a new fact and a list of existing facts, return ONLY a valid JSON array of IDs of existing facts that are directly contradicted or superseded by the new fact. Return an empty array [] if none are contradicted. Do not include facts that are compatible with the new fact.',
            userPrompt: "New fact: \"{$newFact}\"\n\nExisting facts:\n{$existingJson}\n\nReturn a JSON array of IDs to invalidate:",
            maxTokens: 256,
            teamId: $teamId,
            purpose: 'contradiction_detection',
            temperature: 0.1,
        );

        try {
            $response = $this->gateway->complete($request);
            $content = trim($response->content ?? '');

            // Strip markdown code fences
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```\w*\n?/', '', $content);
                $content = preg_replace('/\n?```$/', '', $content);
            }

            $ids = json_decode(trim($content), true);

            if (! is_array($ids)) {
                return [];
            }

            // Validate returned IDs are actually in our candidate set
            $validIds = $candidates->pluck('id')->toArray();

            return array_values(array_filter($ids, fn ($id) => in_array($id, $validIds, true)));
        } catch (\Throwable $e) {
            Log::warning('DetectContradictionAction: LLM check failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function parseEmbedding(string $embeddingStr): array
    {
        // pgvector format: "[0.1,0.2,...]"
        $stripped = trim($embeddingStr, '[]');

        return array_map('floatval', explode(',', $stripped));
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $val) {
            $dot += $val * $b[$i];
            $normA += $val ** 2;
            $normB += $b[$i] ** 2;
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
