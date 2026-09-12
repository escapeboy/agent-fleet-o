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

-- Step 6: Lock the table down — Row Level Security ON by default
-- Supabase grants full table access on new public-schema tables to the `anon` and
-- `authenticated` roles, and the anon key is public (it ships inside browser apps).
-- Without RLS anyone holding that key can read every memory over the REST API.
-- FleetQ connects with the service_role key, which bypasses RLS, so this costs
-- you nothing and is not optional.
ALTER TABLE fleetq_memories ENABLE ROW LEVEL SECURITY;

REVOKE ALL ON TABLE fleetq_memories FROM anon, authenticated;

REVOKE ALL ON FUNCTION fleetq_match_memories(extensions.vector({{EMBEDDING_DIMENSION}}), FLOAT, INT)
    FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION fleetq_match_memories(extensions.vector({{EMBEDDING_DIMENSION}}), FLOAT, INT)
    TO service_role;

-- Earlier versions of this file suggested a "Service role full access" policy with
-- USING (true). That policy had no TO clause, so it applied to every role including
-- `anon`. service_role bypasses RLS anyway and needs no policy. Drop it if present.
DROP POLICY IF EXISTS "Service role full access" ON fleetq_memories;

-- Optional: let signed-in users read their own memories straight from your client app.
-- Skip this entirely if only FleetQ touches the table. Keep the TO authenticated clause —
-- a policy without it also covers `anon`.
-- ALTER TABLE fleetq_memories ADD COLUMN IF NOT EXISTS user_id UUID DEFAULT auth.uid();
-- GRANT SELECT ON fleetq_memories TO authenticated;
-- CREATE POLICY "Users read own memories" ON fleetq_memories
--     FOR SELECT TO authenticated USING (auth.uid() = user_id);

-- Verify the result:
--   SELECT relrowsecurity FROM pg_class WHERE relname = 'fleetq_memories';   -- expect: t
--   SELECT policyname, roles, qual FROM pg_policies WHERE tablename = 'fleetq_memories';
