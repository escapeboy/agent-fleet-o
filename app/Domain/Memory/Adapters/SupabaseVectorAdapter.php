<?php

namespace App\Domain\Memory\Adapters;

use Illuminate\Support\Facades\Http;

/**
 * Supabase pgvector memory adapter.
 *
 * Stores and retrieves agent memories in a client-owned Supabase project
 * using the pgvector extension for semantic similarity search.
 *
 * Before using, the client must run the setup SQL:
 *   base/database/sql/supabase_memory_setup.sql
 *
 * This creates the `fleetq_memories` table and `fleetq_match_memories` function
 * in the client's Supabase project.
 *
 * Usage:
 *   $adapter = new SupabaseVectorAdapter(
 *       projectUrl: 'https://xyzabcdef.supabase.co',
 *       serviceRoleKey: $decryptedKey
 *   );
 *   $id = $adapter->store('Some fact', $embedding, ['agent_id' => 'uuid']);
 *   $results = $adapter->search($queryEmbedding, threshold: 0.78, limit: 10);
 *
 * @see https://supabase.com/docs/guides/database/extensions/pgvector
 */
class SupabaseVectorAdapter
{
    public function __construct(
        private readonly string $projectUrl,
        private readonly string $serviceRoleKey,
    ) {}

    /**
     * Store a memory with its embedding in the client's Supabase project.
     *
     * @param  float[]  $embedding  Vector from your embedding model
     * @param  array<string, mixed>  $metadata  Arbitrary key-value pairs stored alongside the memory
     * @return string The UUID of the inserted record
     */
    public function store(string $content, array $embedding, array $metadata = []): string
    {
        $response = Http::withHeaders($this->headers())
            ->withQueryParameters(['select' => 'id'])
            ->withHeader('Prefer', 'return=representation')
            ->timeout(15)
            ->post($this->restUrl('fleetq_memories'), [
                'content' => $content,
                'embedding' => $embedding,
                'metadata' => $metadata,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "SupabaseVectorAdapter store failed: HTTP {$response->status()} — {$response->body()}",
            );
        }

        $rows = $response->json();

        return $rows[0]['id'] ?? throw new \RuntimeException('SupabaseVectorAdapter: no id returned after insert');
    }

    /**
     * Search for semantically similar memories using cosine similarity.
     *
     * Requires the `fleetq_match_memories` Postgres function to be installed.
     *
     * @param  float[]  $queryEmbedding  Query vector
     * @param  float  $threshold  Minimum cosine similarity (0-1, default 0.78)
     * @param  int  $limit  Maximum number of results
     * @return array<int, array{id: string, content: string, similarity: float, metadata: array}>
     */
    public function search(array $queryEmbedding, float $threshold = 0.78, int $limit = 10): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->post($this->restUrl('rpc/fleetq_match_memories'), [
                'query_embedding' => $queryEmbedding,
                'match_threshold' => $threshold,
                'match_count' => $limit,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "SupabaseVectorAdapter search failed: HTTP {$response->status()} — {$response->body()}",
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Delete a memory by ID.
     */
    public function delete(string $id): void
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->delete($this->restUrl('fleetq_memories'), ['id' => "eq.{$id}"]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "SupabaseVectorAdapter delete failed: HTTP {$response->status()} — {$response->body()}",
            );
        }
    }

    /**
     * Get the setup SQL that the client needs to run once in their Supabase project.
     * This creates the table, function, and index needed for vector search.
     */
    public static function getSetupSql(int $embeddingDimension = 1536): string
    {
        $sqlPath = dirname(__DIR__, 4).'/database/sql/supabase_memory_setup.sql';

        if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);

            // Replace placeholder dimension with the actual model's dimension
            return str_replace('{{EMBEDDING_DIMENSION}}', (string) $embeddingDimension, $sql);
        }

        // Fallback inline SQL if file not found
        return self::inlineSetupSql($embeddingDimension);
    }

    private static function inlineSetupSql(int $dim): string
    {
        return <<<SQL
-- FleetQ Vector Memory Setup for Supabase
-- Run this once in your Supabase SQL editor.

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA extensions;

CREATE TABLE IF NOT EXISTS fleetq_memories (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    content     TEXT NOT NULL,
    embedding   extensions.vector({$dim}),
    metadata    JSONB NOT NULL DEFAULT '{}',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS fleetq_memories_embedding_idx
    ON fleetq_memories USING hnsw (embedding extensions.vector_cosine_ops);

CREATE INDEX IF NOT EXISTS fleetq_memories_metadata_idx
    ON fleetq_memories USING gin (metadata);

CREATE OR REPLACE FUNCTION fleetq_match_memories (
    query_embedding extensions.vector({$dim}),
    match_threshold FLOAT,
    match_count     INT
)
RETURNS TABLE (
    id          UUID,
    content     TEXT,
    similarity  FLOAT,
    metadata    JSONB
)
LANGUAGE SQL STABLE
AS \$\$
    SELECT
        id,
        content,
        1 - (embedding <=> query_embedding) AS similarity,
        metadata
    FROM fleetq_memories
    WHERE 1 - (embedding <=> query_embedding) > match_threshold
    ORDER BY embedding <=> query_embedding ASC
    LIMIT match_count;
\$\$;
SQL;
    }

    private function headers(): array
    {
        return [
            'apikey' => $this->serviceRoleKey,
            'Authorization' => "Bearer {$this->serviceRoleKey}",
            'Content-Type' => 'application/json',
        ];
    }

    private function restUrl(string $path): string
    {
        return rtrim($this->projectUrl, '/').'/rest/v1/'.$path;
    }
}
