<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kg_communities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->string('label', 255)->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('entity_ids')->default('[]');
            $table->integer('size')->default(0);
            $table->jsonb('top_entities')->default('[]');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            $hasVector = DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'");
            if ($hasVector) {
                DB::statement('ALTER TABLE kg_communities ADD COLUMN IF NOT EXISTS summary_embedding vector(1536)');
                DB::statement('CREATE INDEX IF NOT EXISTS kg_communities_summary_embedding_hnsw ON kg_communities USING hnsw (summary_embedding vector_cosine_ops)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kg_communities');
    }
};
