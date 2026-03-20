<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_datasets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('case_count')->default(0);
            $table->timestamps();
        });

        Schema::create('evaluation_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('dataset_id')->index();
            $table->uuid('team_id')->index();
            $table->text('input');
            $table->text('expected_output')->nullable();
            $table->text('context')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('dataset_id')->references('id')->on('evaluation_datasets')->cascadeOnDelete();
        });

        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('dataset_id')->nullable();
            $table->uuid('experiment_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->jsonb('criteria')->default('[]');
            $table->jsonb('aggregate_scores')->default('{}');
            $table->unsignedInteger('total_cost_credits')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('dataset_id')->references('id')->on('evaluation_datasets')->nullOnDelete();
            $table->foreign('experiment_id')->references('id')->on('experiments')->nullOnDelete();
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
        });

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->uuid('case_id')->nullable();
            $table->string('criterion', 64);
            $table->decimal('score', 4, 2);
            $table->text('reasoning')->nullable();
            $table->string('judge_model', 128)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('run_id')->references('id')->on('evaluation_runs')->cascadeOnDelete();
            $table->foreign('case_id')->references('id')->on('evaluation_cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluation_runs');
        Schema::dropIfExists('evaluation_cases');
        Schema::dropIfExists('evaluation_datasets');
    }
};
