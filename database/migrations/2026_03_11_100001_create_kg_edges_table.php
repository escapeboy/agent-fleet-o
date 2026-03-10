<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kg_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->foreignUuid('source_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('target_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('relation_type', 80);   // works_at, has_price, has_status, acquired_by, …
            $table->text('fact');                  // human-readable: "Alice Chen is CEO at Acme Corp"
            $table->timestampTz('valid_at')->nullable();   // when fact became true
            $table->timestampTz('invalid_at')->nullable(); // when fact stopped being true (null = current)
            $table->timestampTz('expired_at')->nullable(); // when superseded by contradiction
            $table->uuid('episode_id')->nullable();        // source Signal UUID
            $table->jsonb('attributes')->default('{}');
            $table->timestamps();

            $table->index(['team_id', 'source_entity_id', 'invalid_at']);
            $table->index(['team_id', 'target_entity_id', 'invalid_at']);
            $table->index(['team_id', 'relation_type', 'invalid_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            // Add a plain text column for SQLite (test environment)
            Schema::table('kg_edges', function (Blueprint $table) {
                $table->text('fact_embedding')->nullable();
            });

            return;
        }

        DB::statement('CREATE INDEX kg_edges_attributes_idx ON kg_edges USING gin (attributes)');

        // Only add vector column if pgvector extension is installed
        $hasVector = DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;

        if ($hasVector) {
            DB::statement('ALTER TABLE kg_edges ADD COLUMN fact_embedding vector(1536)');
            DB::statement('CREATE INDEX kg_edges_embedding_idx ON kg_edges USING hnsw (fact_embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kg_edges');
    }
};
