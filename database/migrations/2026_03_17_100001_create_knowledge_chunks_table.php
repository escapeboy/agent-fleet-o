<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('knowledge_base_id');
            $table->text('content');
            $table->string('source_name', 500)->default('manual');
            $table->string('source_type', 50)->default('manual'); // file, url, text
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases')->cascadeOnDelete();
            $table->index(['knowledge_base_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX knowledge_chunks_metadata_idx ON knowledge_chunks USING gin (metadata)');

        $hasVector = DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;

        if ($hasVector) {
            DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
