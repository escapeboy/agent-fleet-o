<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->nullable()->index();
            $table->uuid('tool_id')->index();
            $table->string('prism_tool_name', 128);
            $table->text('text_content');
            $table->timestamps();

            $table->foreign('tool_id')->references('id')->on('tools')->cascadeOnDelete();
            $table->unique(['tool_id', 'prism_tool_name']);
        });

        // Add vector column only when pgvector extension is available
        if (DB::getDriverName() === 'pgsql') {
            $hasVector = DB::selectOne("SELECT COUNT(*) AS cnt FROM pg_extension WHERE extname = 'vector'");
            if ($hasVector && $hasVector->cnt > 0) {
                DB::statement('ALTER TABLE tool_embeddings ADD COLUMN embedding vector(1536)');
                DB::statement('CREATE INDEX idx_tool_embeddings_embedding ON tool_embeddings USING hnsw (embedding vector_cosine_ops)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_embeddings');
    }
};
