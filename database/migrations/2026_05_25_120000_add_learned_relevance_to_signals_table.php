<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            // Learned, team-personalised relevance (distinct from the stateless
            // LLM `relevance_score`). Populated by SignalRelevanceScorer.
            $table->float('learned_relevance_score')->nullable();
            $table->timestamp('learned_relevance_at')->nullable();
        });

        // pgvector embedding column + HNSW index — guarded so SQLite test
        // environments (which lack the extension) silently no-op. Raw SQL because
        // Laravel's schema builder does not understand the vector type.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $hasVector = DB::selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'vector'");
            if ($hasVector && ! Schema::hasColumn('signals', 'embedding')) {
                DB::statement('ALTER TABLE signals ADD COLUMN embedding vector(1536)');
                DB::statement('CREATE INDEX signals_embedding_idx ON signals USING hnsw (embedding vector_cosine_ops)');
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS signals_embedding_idx');
            if (Schema::hasColumn('signals', 'embedding')) {
                DB::statement('ALTER TABLE signals DROP COLUMN embedding');
            }
        }

        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['learned_relevance_score', 'learned_relevance_at']);
        });
    }
};
