<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reasoning_bank_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->text('goal_text');
            $table->jsonb('tool_sequence')->default('[]');
            $table->text('outcome_summary');
            $table->timestamps();

            $table->index('team_id');
            $table->index('experiment_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasVector = DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;

        if ($hasVector) {
            DB::statement('ALTER TABLE reasoning_bank_entries ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX reasoning_bank_entries_embedding_idx ON reasoning_bank_entries USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reasoning_bank_entries_embedding_idx');
        Schema::dropIfExists('reasoning_bank_entries');
    }
};
