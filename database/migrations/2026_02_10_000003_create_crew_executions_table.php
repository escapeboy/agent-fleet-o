<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('crew_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->text('goal');
            $table->string('status', 30)->default('planning');
            $table->jsonb('task_plan')->default('[]');
            $table->jsonb('final_output')->nullable();
            $table->jsonb('config_snapshot')->default('{}');
            $table->decimal('quality_score', 3, 2)->nullable();
            $table->integer('coordinator_iterations')->default(0);
            $table->integer('total_cost_credits')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index('crew_id');
            $table->index('experiment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_executions');
    }
};
