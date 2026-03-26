<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_elements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();

            // Element type: 'file' | 'class' | 'function' | 'method'
            $table->string('element_type', 20);
            $table->string('name', 500);
            $table->text('file_path');
            $table->integer('line_start')->nullable();
            $table->integer('line_end')->nullable();
            $table->text('signature')->nullable();
            $table->text('docstring')->nullable();

            // SHA-256 hash of element content for change detection
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index(['git_repository_id', 'element_type']);
            $table->index(['team_id', 'file_path']);
        });

        // Add pgvector embedding column and HNSW index only when the extension is available
        if (DB::getDriverName() === 'pgsql') {
            $hasVector = DB::selectOne("SELECT COUNT(*) AS cnt FROM pg_extension WHERE extname = 'vector'");
            if ($hasVector && $hasVector->cnt > 0) {
                DB::statement('ALTER TABLE code_elements ADD COLUMN embedding vector(1536)');
                DB::statement('CREATE INDEX idx_code_elements_embedding ON code_elements USING hnsw (embedding vector_cosine_ops)');
            }
        }

        // Add full-text search vector column and GIN index (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE code_elements ADD COLUMN search_vector tsvector');
            DB::statement('CREATE INDEX idx_code_elements_search ON code_elements USING GIN (search_vector)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_code_elements_embedding');
            DB::statement('DROP INDEX IF EXISTS idx_code_elements_search');
        }

        Schema::dropIfExists('code_elements');
    }
};
