<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extends existing evaluation_datasets with workflow_id + row_count
        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->uuid('workflow_id')->nullable()->after('team_id');
            $table->unsignedInteger('row_count')->default(0)->after('case_count');
            $table->softDeletes();

            $table->foreign('workflow_id')->references('id')->on('workflows')->nullOnDelete();
        });

        Schema::create('evaluation_dataset_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('dataset_id')->index();
            $table->jsonb('input')->default('{}');
            $table->text('expected_output')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('dataset_id')->references('id')->on('evaluation_datasets')->cascadeOnDelete();
        });

        // Extend evaluation_runs with workflow_id, judge_model, judge_prompt, summary
        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->uuid('workflow_id')->nullable()->after('agent_id');
            $table->string('judge_model', 128)->default('claude-haiku-4-5-20251001')->after('workflow_id');
            $table->text('judge_prompt')->nullable()->after('judge_model');
            $table->jsonb('summary')->nullable()->after('judge_prompt');

            $table->foreign('workflow_id')->references('id')->on('workflows')->nullOnDelete();
        });

        Schema::create('evaluation_run_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->uuid('row_id')->nullable();
            $table->text('actual_output')->nullable();
            $table->decimal('score', 4, 2)->nullable();
            $table->text('judge_reasoning')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('run_id')->references('id')->on('evaluation_runs')->cascadeOnDelete();
            $table->foreign('row_id')->references('id')->on('evaluation_dataset_rows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_run_results');
        Schema::dropIfExists('evaluation_dataset_rows');

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropForeign(['workflow_id']);
            $table->dropColumn(['workflow_id', 'judge_model', 'judge_prompt', 'summary']);
        });

        Schema::table('evaluation_datasets', function (Blueprint $table) {
            $table->dropForeign(['workflow_id']);
            $table->dropColumn(['workflow_id', 'row_count']);
            $table->dropSoftDeletes();
        });
    }
};
