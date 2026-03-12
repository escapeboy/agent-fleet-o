<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_kb_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained('chatbot_knowledge_sources')->cascadeOnDelete();
            $table->foreignUuid('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('chunk_index')->default(0);
            $table->jsonb('metadata')->nullable(); // page, heading, url, token_count
            $table->timestamps();

            $table->index(['chatbot_id', 'source_id']);
            $table->index(['team_id', 'chatbot_id']);
        });

        // Add vector column (pgvector 1536-dim for text-embedding-3-small / ada-002)
        // Only if running on PostgreSQL with pgvector extension installed
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasVector = DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;
        if ($hasVector) {
            DB::statement('ALTER TABLE chatbot_kb_chunks ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX chatbot_kb_chunks_embedding_hnsw_idx ON chatbot_kb_chunks USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_kb_chunks');
    }
};
