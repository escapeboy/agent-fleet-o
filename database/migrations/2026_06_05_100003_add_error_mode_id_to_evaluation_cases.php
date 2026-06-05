<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentic AI Flywheel — link evaluation cases to the error-mode catalog.
 * Runs after error_modes exists (migration 100002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_cases', function (Blueprint $table) {
            $table->uuid('error_mode_id')->nullable()->after('error_mode');
            $table->foreign('error_mode_id')->references('id')->on('error_modes')->nullOnDelete();
            $table->index(['team_id', 'error_mode_id'], 'eval_cases_team_error_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_cases', function (Blueprint $table) {
            $table->dropForeign(['error_mode_id']);
            $table->dropIndex('eval_cases_team_error_mode_idx');
            $table->dropColumn('error_mode_id');
        });
    }
};
