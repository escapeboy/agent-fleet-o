<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_iteration_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('benchmark_id')->constrained('skill_benchmarks')->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('skill_versions')->nullOnDelete();
            $table->integer('iteration_number');
            $table->float('metric_value')->nullable();
            $table->float('baseline_at_iteration');
            $table->integer('complexity_delta')->nullable();
            $table->float('effective_improvement')->nullable();
            $table->string('outcome');
            $table->text('diff_summary')->nullable();
            $table->text('crash_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index(['benchmark_id', 'iteration_number']);
            $table->index(['skill_id', 'outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_iteration_logs');
    }
};
