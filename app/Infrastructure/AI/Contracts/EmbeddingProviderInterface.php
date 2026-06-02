<?php

namespace App\Infrastructure\AI\Contracts;

/**
 * Sanctioned seam for generating embedding vectors. The cloud default
 * (EmbeddingService → Prism → OpenAI) implements this; a future local
 * backend (e.g. an FFI binding such as NeuraPHP/embedding.cpp) can bind
 * here without touching consumers. Selected by config('memory.embedding_driver').
 */
interface EmbeddingProviderInterface
{
    /**
     * Generate an embedding vector for the given text.
     *
     * @return float[]
     */
    public function embed(string $text): array;

    /**
     * Team-aware variant resolving a usable key (BYOK → platform → env).
     * Returns null when no key is reachable so callers degrade gracefully.
     *
     * @return float[]|null
     */
    public function embedForTeam(string $text, ?string $teamId): ?array;

    /**
     * Format a float[] embedding as a pgvector literal, e.g. "[0.1,0.2,...]".
     *
     * @param  float[]  $embedding
     */
    public function formatForPgvector(array $embedding): string;

    /**
     * Vector dimensionality this provider emits. Must match the pgvector
     * column it writes into.
     */
    public function dimensions(): int;

    /**
     * Stable namespace key for vectors this provider produces, e.g.
     * "openai:text-embedding-3-small". Used to keep vectors from different
     * embedding models from being compared against each other.
     */
    public function identifier(): string;
}
