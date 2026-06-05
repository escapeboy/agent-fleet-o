<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentic AI Flywheel #5 — continuous production eval monitor snapshots.
 * One row per monitor run; the time series feeds the drift score-decay signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_monitor_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('dataset_id')->nullable();
            $table->uuid('run_id')->nullable();
            $table->decimal('avg_score', 4, 2)->nullable();
            $table->decimal('pass_rate', 5, 2)->nullable();
            $table->unsignedInteger('active_count')->default(0);
            $table->unsignedInteger('deferred_passed')->default(0);
            $table->unsignedInteger('sampled_count')->default(0);
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('dataset_id')->references('id')->on('evaluation_datasets')->nullOnDelete();
            $table->index(['team_id', 'dataset_id', 'created_at'], 'eval_monitor_team_dataset_idx');
            $table->index(['team_id', 'created_at'], 'eval_monitor_team_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_monitor_snapshots');
    }
};
