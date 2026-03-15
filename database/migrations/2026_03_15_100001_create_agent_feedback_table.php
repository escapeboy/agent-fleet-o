<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignUuid('agent_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('crew_task_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('experiment_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('human'); // human | llm_judge | automated
            $table->string('feedback_type', 20)->default('binary'); // binary | rating | correction | label
            $table->smallInteger('score')->nullable(); // -1/1 (binary) or 1-5 (rating)
            $table->string('label', 50)->nullable(); // accuracy | tone | format | completeness
            $table->text('correction')->nullable();
            $table->text('comment')->nullable();
            $table->text('output_snapshot')->nullable();
            $table->text('input_snapshot')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->timestamp('feedback_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
            $table->index(['agent_id', 'score']);
            $table->index(['team_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_feedback');
    }
};
