<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('agent_id');
            $table->uuid('project_id')->nullable();
            $table->text('content');
            $table->json('metadata')->default('{}');
            $table->string('source_type', 50);  // execution, manual, signal
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();

            $table->index(['agent_id', 'project_id']);
        });

        // PostgreSQL-specific: vector column and indexes (requires pgvector extension)
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::statement('ALTER TABLE memories ADD COLUMN embedding vector(1536)');
                DB::statement('CREATE INDEX memories_embedding_idx ON memories USING hnsw (embedding vector_cosine_ops)');
            } catch (Throwable) {
                // pgvector not available — embedding column skipped, memory search will use text-only fallback
            }

            DB::statement('CREATE INDEX memories_metadata_idx ON memories USING gin (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
