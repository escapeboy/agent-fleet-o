<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills the pgvector `embedding` columns + HNSW indexes that earlier
 * migrations SKIPPED because the running Postgres image (postgres:17-alpine)
 * did not ship the `vector` extension. Now that the DB runs a pgvector-enabled
 * image, this migration enables the extension and adds any missing vector
 * columns so the embedding/similarity features (SemanticCache, Memory search,
 * KnowledgeGraph, learned signal relevance, etc.) light up.
 *
 * Idempotent + guarded: no-ops on non-Postgres (SQLite tests) and on fresh
 * databases where the original migrations already created the columns once the
 * extension was available.
 */
return new class extends Migration
{
    /**
     * @return array<int, array{0: string, 1: string, 2: string|null}> [table, column, indexName|null]
     */
    private function specs(): array
    {
        return [
            ['memories', 'embedding', 'memories_embedding_idx'],
            ['memories', 'embedding_at_creation', null],
            ['semantic_cache_entries', 'embedding', 'semantic_cache_embedding_idx'],
            ['kg_edges', 'fact_embedding', 'kg_edges_embedding_idx'],
            ['kg_communities', 'summary_embedding', 'kg_communities_summary_embedding_hnsw'],
            ['skill_embeddings', 'embedding', 'skill_embeddings_embedding_idx'],
            ['tool_embeddings', 'embedding', 'idx_tool_embeddings_embedding'],
            ['tool_registry_entries', 'embedding', 'tool_registry_entries_embedding_hnsw'],
            ['knowledge_chunks', 'embedding', 'knowledge_chunks_embedding_idx'],
            ['code_elements', 'embedding', 'idx_code_elements_embedding'],
            ['reasoning_bank_entries', 'embedding', 'reasoning_bank_entries_embedding_idx'],
            ['chatbot_kb_chunks', 'embedding', 'chatbot_kb_chunks_embedding_hnsw_idx'],
            ['signals', 'embedding', 'signals_embedding_idx'],
        ];
    }

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $available = DB::scalar("SELECT COUNT(*) FROM pg_available_extensions WHERE name = 'vector'") > 0;
        if (! $available) {
            // Postgres build still lacks pgvector — leave the schema untouched.
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        foreach ($this->specs() as [$table, $column, $index]) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} vector(1536)");

            if ($index !== null) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON {$table} USING hnsw ({$column} vector_cosine_ops)");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->specs() as [$table, $column, $index]) {
            if ($index !== null) {
                DB::statement("DROP INDEX IF EXISTS {$index}");
            }

            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
            }
        }
    }
};
