<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accurate usage cost (UsageNormalizer): shadow cost on request logs while the
 * flag is off, and cache-write tokens on runs for platform-credit deduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table) {
            $table->integer('accurate_cost_credits')->nullable();
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->integer('cache_write_input_tokens')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table) {
            $table->dropColumn('accurate_cost_credits');
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('cache_write_input_tokens');
        });
    }
};
