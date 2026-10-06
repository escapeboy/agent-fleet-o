<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            // Bumped on every knowledge-source change; cache rows of older generations are never served.
            $table->unsignedInteger('answer_cache_generation')->default(0);
        });

        Schema::create('chatbot_answer_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->char('prompt_hash', 64);
            $table->text('question');
            $table->char('question_hash', 64);
            $table->text('answer');
            $table->jsonb('sources')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedInteger('generation_tokens')->nullable();
            $table->unsignedInteger('generation_cost_credits')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // The unique index below also serves (chatbot_id, generation, prompt_hash) lookups.
            $table->unique(['chatbot_id', 'generation', 'prompt_hash', 'question_hash'], 'chatbot_answer_cache_question_unique');
            $table->index('expires_at');
        });

        // Same 1536-dim space as chatbot_kb_chunks. No HNSW: a chatbot holds at most
        // a few hundred rows, scanned exactly behind the btree filter above.
        if (DB::getDriverName() === 'pgsql'
            && DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0) {
            DB::statement('ALTER TABLE chatbot_answer_cache ADD COLUMN embedding vector(1536)');
        } else {
            Schema::table('chatbot_answer_cache', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_answer_cache');

        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn('answer_cache_generation');
        });
    }
};
