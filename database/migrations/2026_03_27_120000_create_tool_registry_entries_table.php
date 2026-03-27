<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_registry_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tool_name')->unique();
            $table->string('group');
            $table->text('description');
            $table->text('composite_text'); // concatenation used as embedding input
            $table->jsonb('schema')->default('{}');
            $table->timestamps();
        });

        // pgvector embedding column and HNSW index — guarded for non-PostgreSQL environments (e.g. SQLite in tests)
        if (DB::getDriverName() === 'pgsql'
            && DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0
        ) {
            DB::statement('ALTER TABLE tool_registry_entries ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX tool_registry_entries_embedding_hnsw ON tool_registry_entries USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_registry_entries');
    }
};
