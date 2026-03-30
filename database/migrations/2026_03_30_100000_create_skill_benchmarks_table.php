<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_benchmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('best_version_id')->nullable()->constrained('skill_versions')->nullOnDelete();
            $table->string('metric_name');
            $table->string('metric_direction')->default('maximize');
            $table->float('baseline_value')->nullable();
            $table->float('best_value')->nullable();
            $table->jsonb('test_inputs')->default('[]');
            $table->integer('iteration_count')->default(0);
            $table->integer('max_iterations')->default(50);
            $table->integer('time_budget_seconds')->default(3600);
            $table->integer('iteration_budget_seconds')->default(60);
            $table->float('complexity_penalty')->default(0.01);
            $table->float('improvement_threshold')->default(0.0);
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();

            $table->index(['skill_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_benchmarks');
    }
};
