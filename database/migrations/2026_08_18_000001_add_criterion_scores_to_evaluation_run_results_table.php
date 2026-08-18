<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_run_results', function (Blueprint $table) {
            // Per-criterion judge scores for this case, e.g. {"correctness": 8.5, "relevance": 9.0}.
            // Needed so an evaluation run's aggregate can be recomputed from persisted rows
            // (order-independently) after a parallel, fan-out replay — not just accumulated in
            // memory during a sequential loop.
            $table->jsonb('criterion_scores')->nullable()->after('score');
            // Target + judge credit cost for this single case, summed into the run total at finalize.
            $table->integer('cost_credits')->nullable()->after('criterion_scores');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_run_results', function (Blueprint $table) {
            $table->dropColumn(['criterion_scores', 'cost_credits']);
        });
    }
};
