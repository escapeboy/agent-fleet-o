<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiment_state_transitions', function (Blueprint $table) {
            // Stores DoneVerdict from DoneConditionJudge when the gate runs.
            // Null means the gate was disabled or not applicable to this transition.
            $table->jsonb('judge_verdict')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('experiment_state_transitions', function (Blueprint $table) {
            $table->dropColumn('judge_verdict');
        });
    }
};
