<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            // Make experiment_id nullable (was non-null with cascade delete)
            $table->dropForeign(['experiment_id']);
            $table->uuid('experiment_id')->nullable()->change();
            $table->foreign('experiment_id')->references('id')->on('experiments')->nullOnDelete();

            // Add new owner FKs
            $table->foreignUuid('crew_execution_id')->nullable()
                ->after('experiment_id')
                ->constrained('crew_executions')->nullOnDelete();

            $table->foreignUuid('project_run_id')->nullable()
                ->after('crew_execution_id')
                ->constrained('project_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropForeign(['project_run_id']);
            $table->dropColumn('project_run_id');

            $table->dropForeign(['crew_execution_id']);
            $table->dropColumn('crew_execution_id');

            // Restore experiment_id as non-nullable
            $table->dropForeign(['experiment_id']);
            $table->uuid('experiment_id')->nullable(false)->change();
            $table->foreign('experiment_id')->references('id')->on('experiments')->cascadeOnDelete();
        });
    }
};
