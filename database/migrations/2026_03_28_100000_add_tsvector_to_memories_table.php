<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only run on PostgreSQL — SQLite does not support tsvector.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Ensure pg_trgm is available for the trigram fallback.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Add generated tsvector column for full-text search.
        DB::statement("
            ALTER TABLE memories
            ADD COLUMN IF NOT EXISTS content_tsv tsvector
            GENERATED ALWAYS AS (to_tsvector('english', coalesce(content, ''))) STORED
        ");

        // GIN index enables fast @@ FTS queries.
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_memories_content_tsv
            ON memories USING gin(content_tsv)
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_memories_content_tsv');
        DB::statement('ALTER TABLE memories DROP COLUMN IF EXISTS content_tsv');
    }
};
