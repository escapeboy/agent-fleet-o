<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semantic_cache_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->nullable()->index();
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('prompt_hash', 32)->index(); // xxh128 for fast exact match
            $table->text('request_text');               // combined system+user prompt for reference
            $table->text('response_content');
            $table->jsonb('response_metadata')->default('{}'); // prompt_tokens, completion_tokens, parsed_output
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'provider', 'model']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Only add vector column if pgvector extension is installed
        $hasVector = DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;

        if ($hasVector) {
            DB::statement('ALTER TABLE semantic_cache_entries ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX semantic_cache_embedding_idx ON semantic_cache_entries USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('semantic_cache_entries');
    }
};
