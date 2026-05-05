<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('memories')) {
            return;
        }

        // Use raw SQL because the embedding column is pgvector (1536-dim vector)
        // and Laravel's schema builder doesn't natively understand vector types.
        // Guarded with `pg_extension` lookup so test environments without
        // the vector extension installed don't fail migrations — drift
        // detection silently no-ops in such environments.
        if (DB::connection()->getDriverName() === 'pgsql' && ! Schema::hasColumn('memories', 'embedding_at_creation')) {
            $hasVector = DB::selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'vector'");
            if ($hasVector) {
                DB::statement('ALTER TABLE memories ADD COLUMN embedding_at_creation vector(1536) NULL');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('memories')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasColumn('memories', 'embedding_at_creation')) {
            DB::statement('ALTER TABLE memories DROP COLUMN embedding_at_creation');
        }
    }
};
