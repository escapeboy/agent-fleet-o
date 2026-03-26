<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            if (DB::getDriverName() === 'pgsql') {
                // vector column added raw below
            }
            $table->timestamps();
        });

        // Add pgvector column and HNSW index only when extension is available
        if (DB::getDriverName() === 'pgsql') {
            $hasVector = DB::selectOne("SELECT COUNT(*) AS cnt FROM pg_extension WHERE extname = 'vector'");
            if ($hasVector && $hasVector->cnt > 0) {
                DB::statement('ALTER TABLE skill_embeddings ADD COLUMN embedding vector(1536)');
                DB::statement('CREATE INDEX skill_embeddings_embedding_idx ON skill_embeddings USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_embeddings');
    }
};
