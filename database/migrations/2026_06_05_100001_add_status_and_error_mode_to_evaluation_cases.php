<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentic AI Flywheel #2 — DEFER status + provenance on evaluation cases.
 * Backward-compatible: existing cases default to active / manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_cases', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('expected_output');
            $table->string('source', 32)->default('manual')->after('status');
            $table->string('error_mode', 160)->nullable()->after('source');

            $table->index(['team_id', 'status'], 'eval_cases_team_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_cases', function (Blueprint $table) {
            $table->dropIndex('eval_cases_team_status_idx');
            $table->dropColumn(['status', 'source', 'error_mode']);
        });
    }
};
