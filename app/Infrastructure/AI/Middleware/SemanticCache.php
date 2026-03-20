<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Models\SemanticCacheEntry;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class SemanticCache implements AiMiddlewareInterface
{
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if (! config('semantic_cache.enabled', false)) {
            return $next($request);
        }

        // Skip for local agents (zero-cost, no point caching)
        if (str_starts_with($request->provider, 'local/')) {
            return $next($request);
        }

        // Skip if pgvector extension is not available
        if (! $this->hasVectorExtension()) {
            return $next($request);
        }

        $requestText = trim($request->systemPrompt.' '.$request->userPrompt);
        $promptHash = hash('xxh128', $requestText);
        $threshold = config('semantic_cache.similarity_threshold', 0.92);

        // Fast-path: exact text match
        $hit = $this->findExactMatch($request->teamId, $request->provider, $request->model, $promptHash);

        // Semantic match via embedding similarity
        if (! $hit) {
            try {
                $embedding = $this->generateEmbedding($requestText);
                $hit = $this->findSemanticMatch($request->teamId, $request->provider, $request->model, $embedding, $threshold);
            } catch (\Throwable $e) {
                Log::warning('SemanticCache: embedding generation failed, bypassing cache', [
                    'error' => $e->getMessage(),
                ]);

                return $next($request);
            }
        }

        if ($hit) {
            $hit->increment('hit_count');
            $metadata = $hit->response_metadata ?? [];

            return new AiResponseDTO(
                content: $hit->response_content,
                parsedOutput: $metadata['parsed_output'] ?? null,
                usage: new AiUsageDTO(
                    promptTokens: $metadata['prompt_tokens'] ?? 0,
                    completionTokens: $metadata['completion_tokens'] ?? 0,
                    costCredits: 0,
                ),
                provider: $hit->provider,
                model: $hit->model,
                latencyMs: 0,
                cached: true,
            );
        }

        $response = $next($request);

        // Store response in cache; don't let storage failures affect the caller
        try {
            if (isset($embedding)) {
                $this->storeEntry($request, $promptHash, $requestText, $embedding, $response);
            }
        } catch (\Throwable $e) {
            Log::warning('SemanticCache: failed to store cache entry', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function findExactMatch(?string $teamId, string $provider, string $model, string $promptHash): ?SemanticCacheEntry
    {
        return SemanticCacheEntry::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('prompt_hash', $promptHash)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    private function findSemanticMatch(?string $teamId, string $provider, string $model, string $embedding, float $threshold): ?SemanticCacheEntry
    {
        return SemanticCacheEntry::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereRaw('embedding IS NOT NULL AND (1 - (embedding <=> ?::vector)) >= ?', [$embedding, $threshold])
            ->orderByRaw('embedding <=> ?::vector', [$embedding])
            ->first();
    }

    private function storeEntry(AiRequestDTO $request, string $promptHash, string $requestText, string $embedding, AiResponseDTO $response): void
    {
        $ttlDays = config('semantic_cache.ttl_days', 7);

        SemanticCacheEntry::withoutGlobalScopes()->create([
            'team_id' => $request->teamId,
            'provider' => $request->provider,
            'model' => $request->model,
            'prompt_hash' => $promptHash,
            'request_text' => substr($requestText, 0, config('semantic_cache.request_text_max_length', 10000)),
            'response_content' => $response->content,
            'response_metadata' => [
                'parsed_output' => $response->parsedOutput,
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ],
            'embedding' => $embedding,
            'expires_at' => $ttlDays > 0 ? now()->addDays($ttlDays) : null,
        ]);
    }

    private function hasVectorExtension(): bool
    {
        static $checked = null;

        if ($checked === null) {
            $checked = DB::getDriverName() === 'pgsql'
                && DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;
        }

        return $checked;
    }

    private function generateEmbedding(string $text): string
    {
        $model = config('semantic_cache.embedding_model', 'text-embedding-3-small');

        $response = Prism::embeddings()
            ->using(config('semantic_cache.embedding_provider', 'openai'), $model)
            ->fromInput($text)
            ->asEmbeddings();

        $vector = $response->embeddings[0]->embedding;

        return '['.implode(',', $vector).']';
    }
}
