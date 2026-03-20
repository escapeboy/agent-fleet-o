-- FleetQ Vector Memory Setup for Supabase
-- ==========================================
-- Run this ONCE in your Supabase SQL editor to enable agent memory storage.
-- Project Settings → SQL Editor → New query → paste and run.
--
-- Requirements:
--   - pgvector extension (available on all Supabase plans)
--   - Pro plan recommended for HNSW index performance at scale
--
-- Replace {{EMBEDDING_DIMENSION}} with your embedding model's output dimension:
--   - OpenAI text-embedding-3-small: 1536
--   - OpenAI text-embedding-3-large: 3072
--   - Anthropic (via Voyage): 1024
--   - Google text-embedding-004: 768

-- Step 1: Enable pgvector extension
CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA extensions;

-- Step 2: Create the memories table
CREATE TABLE IF NOT EXISTS fleetq_memories (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    content     TEXT NOT NULL,
    embedding   extensions.vector({{EMBEDDING_DIMENSION}}),
    metadata    JSONB NOT NULL DEFAULT '{}',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE fleetq_memories IS 'FleetQ agent memories with vector embeddings for semantic search.';
COMMENT ON COLUMN fleetq_memories.embedding IS 'Vector embedding — dimension must match the model configured in FleetQ.';
COMMENT ON COLUMN fleetq_memories.metadata IS 'Arbitrary key-value metadata: agent_id, project_id, source, etc.';

-- Step 3: Create HNSW index for fast approximate nearest-neighbor search
-- (IVFFlat alternative: CREATE INDEX ... USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100))
CREATE INDEX IF NOT EXISTS fleetq_memories_embedding_idx
    ON fleetq_memories USING hnsw (embedding extensions.vector_cosine_ops);

-- Step 4: GIN index for metadata filtering
CREATE INDEX IF NOT EXISTS fleetq_memories_metadata_idx
    ON fleetq_memories USING gin (metadata);

-- Step 5: Create the similarity search function
-- FleetQ calls this via POST /rest/v1/rpc/fleetq_match_memories
CREATE OR REPLACE FUNCTION fleetq_match_memories (
    query_embedding extensions.vector({{EMBEDDING_DIMENSION}}),
    match_threshold FLOAT DEFAULT 0.78,
    match_count     INT   DEFAULT 10
)
RETURNS TABLE (
    id          UUID,
    content     TEXT,
    similarity  FLOAT,
    metadata    JSONB
)
LANGUAGE SQL STABLE
AS $$
    SELECT
        id,
        content,
        1 - (embedding <=> query_embedding) AS similarity,
        metadata
    FROM fleetq_memories
    WHERE 1 - (embedding <=> query_embedding) > match_threshold
    ORDER BY embedding <=> query_embedding ASC
    LIMIT match_count;
$$;

COMMENT ON FUNCTION fleetq_match_memories IS
    'Cosine similarity search for FleetQ agent memories. Called via Supabase REST RPC by FleetQ agents.';

-- Optional: Row Level Security
-- Uncomment if you want to restrict access by user JWT:
-- ALTER TABLE fleetq_memories ENABLE ROW LEVEL SECURITY;
-- CREATE POLICY "Service role full access" ON fleetq_memories FOR ALL USING (true);
