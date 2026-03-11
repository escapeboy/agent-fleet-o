<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_kb_chunks', function (Blueprint $table) {
            $table->string('access_level', 20)->default('public')->after('chunk_index');
            $table->index(['chatbot_id', 'access_level']);
        });

        // Partial HNSW indexes per access tier (PostgreSQL + pgvector only)
        if (config('database.default') === 'pgsql') {
            $hasVector = DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;
            if ($hasVector) {
                DB::statement("CREATE INDEX chatbot_kb_chunks_public_embedding_idx
                    ON chatbot_kb_chunks USING hnsw (embedding vector_cosine_ops)
                    WHERE access_level IN ('public', 'key')");
                DB::statement("CREATE INDEX chatbot_kb_chunks_internal_embedding_idx
                    ON chatbot_kb_chunks USING hnsw (embedding vector_cosine_ops)
                    WHERE access_level IN ('internal', 'code')");
            }
        }
    }

    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS chatbot_kb_chunks_public_embedding_idx');
            DB::statement('DROP INDEX IF EXISTS chatbot_kb_chunks_internal_embedding_idx');
        }

        Schema::table('chatbot_kb_chunks', function (Blueprint $table) {
            $table->dropIndex(['chatbot_id', 'access_level']);
            $table->dropColumn('access_level');
        });
    }
};
