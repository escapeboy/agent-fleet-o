<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds default-workflow pointers used by the bug-fix-merge pipeline:
 *
 *   agents.default_workflow_id          — per-agent default workflow
 *                                         resolved by DelegateBugReportToAgentAction.
 *   teams.default_bug_fix_workflow_id   — per-team fallback when the
 *                                         resolved agent has no default.
 *
 * Both columns are nullable + indexed. Backward compatible: when both are
 * null the experiment runs on the legacy stage path (CreateExperimentAction
 * skips materialization).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignUuid('default_workflow_id')
                ->nullable()
                ->after('id')
                ->constrained('workflows')
                ->nullOnDelete();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignUuid('default_bug_fix_workflow_id')
                ->nullable()
                ->after('id')
                ->constrained('workflows')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_workflow_id');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_bug_fix_workflow_id');
        });
    }
};
