<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plain nullable column (no DB-level FK — adding constraints to an existing
        // table is unreliable on SQLite; the relationship is enforced app-side).
        Schema::table('skills', function (Blueprint $table) {
            $table->uuid('eval_dataset_id')->nullable()->after('evaluation_criteria');
        });

        Schema::create('skill_lift_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('skill_id');
            $table->uuid('skill_version_id')->nullable();
            $table->uuid('dataset_id')->nullable();
            $table->string('status')->default('pending');
            $table->jsonb('criteria')->nullable();
            $table->decimal('with_skill_score', 4, 2)->nullable();
            $table->decimal('without_skill_score', 4, 2)->nullable();
            $table->decimal('delta', 5, 2)->nullable();
            $table->decimal('improvement_rate', 5, 4)->nullable();
            $table->string('recommendation')->nullable();
            $table->jsonb('case_results')->nullable();
            $table->string('judge_model')->nullable();
            $table->integer('cost_credits')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();
            $table->foreign('dataset_id')->references('id')->on('evaluation_datasets')->nullOnDelete();
            $table->index(['skill_id', 'status']);
            $table->index(['team_id', 'recommendation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_lift_evaluations');

        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('eval_dataset_id');
        });
    }
};
