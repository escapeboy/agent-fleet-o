<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_task_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('crew_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('status', 30)->default('pending');
            $table->jsonb('input_context')->default('{}');
            $table->jsonb('output')->nullable();
            $table->jsonb('qa_feedback')->nullable();
            $table->decimal('qa_score', 3, 2)->nullable();
            $table->jsonb('depends_on')->default('[]');
            $table->integer('attempt_number')->default(1);
            $table->integer('max_attempts')->default(3);
            $table->integer('cost_credits')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('batch_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['crew_execution_id', 'status']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_task_executions');
    }
};
