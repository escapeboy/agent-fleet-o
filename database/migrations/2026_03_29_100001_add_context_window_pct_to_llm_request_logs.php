<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table) {
            // Fraction (0–100) of the model's context window consumed by this request's
            // input tokens. Populated by UsageTracking middleware for experiments.
            $table->decimal('context_window_pct', 5, 2)->nullable()->after('input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table) {
            $table->dropColumn('context_window_pct');
        });
    }
};
